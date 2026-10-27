"""AI Studio — classic machine learning on a table (model builder part 4).

  python train_table.py <data.csv> <out dir> <target column> [auto|classification|regression]

Reads the CSV (standard library csv; delimiter sniffed), works out each column's type, and drops columns that cannot
help: identifiers (a distinct value on almost every row), free text with too many distinct values, constant columns.
Then it compares a few scikit-learn models with 5-fold cross-validation on 80 % of the rows —
  classification: logistic regression, random forest, gradient boosting (macro-F1)
  regression:     ridge, random forest, gradient boosting (R²)
— measures the winner once on the 20 % it never saw, ranks the columns by permutation importance, refits it on every
row and writes model.joblib, schema.json (the columns a prediction needs, with an example row) and metrics.json.
Progress lines go to stdout as JSON for the Studio to show.
"""
import csv, json, math, os, sys, time
from collections import Counter

import joblib
import numpy as np
from sklearn.compose import ColumnTransformer
from sklearn.ensemble import HistGradientBoostingClassifier, HistGradientBoostingRegressor, RandomForestClassifier, RandomForestRegressor
from sklearn.impute import SimpleImputer
from sklearn.inspection import permutation_importance
from sklearn.linear_model import LogisticRegression, Ridge
from sklearn.metrics import accuracy_score, confusion_matrix, f1_score, mean_absolute_error, mean_squared_error, r2_score
from sklearn.model_selection import KFold, StratifiedKFold, cross_val_score, train_test_split
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder, StandardScaler

MAX_ROWS = 200_000
MISSING = {'', 'na', 'n/a', 'nan', 'null', 'none', '-', '?'}


def say(**kw):
    print(json.dumps(kw, ensure_ascii=False), flush=True)


def num(v):
    v = v.strip().replace(',', '')
    if v.lower() in MISSING: return None
    try:
        f = float(v)
        return f if math.isfinite(f) else None
    except ValueError:
        return 'x'


def read(path):
    with open(path, newline='', encoding='utf-8-sig', errors='replace') as f:
        head = f.read(65536); f.seek(0)
        try: dialect = csv.Sniffer().sniff(head, delimiters=',;\t|')
        except csv.Error: dialect = csv.excel
        rows = list(csv.reader(f, dialect))
    if len(rows) < 2: raise SystemExit('The file has no data rows.')
    header = [h.strip() or f'column_{i + 1}' for i, h in enumerate(rows[0])]
    data = [r + [''] * (len(header) - len(r)) for r in rows[1:] if any(c.strip() for c in r)]
    return header, [r[:len(header)] for r in data]


def main():
    src, out, target = sys.argv[1], sys.argv[2], sys.argv[3]
    task = sys.argv[4] if len(sys.argv) > 4 else 'auto'
    t0 = time.time()
    header, rows = read(src)
    if target not in header: raise SystemExit(f'There is no column called "{target}".')
    ti = header.index(target)
    rows = [r for r in rows if r[ti].strip().lower() not in MISSING]          # rows without an answer cannot teach
    if len(rows) > MAX_ROWS:
        rng = np.random.default_rng(42); rows = [rows[i] for i in sorted(rng.choice(len(rows), MAX_ROWS, replace=False))]
    n = len(rows)
    if n < 20: raise SystemExit(f'Only {n} rows have a value in "{target}"; at least 20 are needed (a few hundred is better).')
    say(phase='reading', rows=n, columns=len(header))

    # --- the target
    yraw = [r[ti].strip() for r in rows]
    ynum = [num(v) for v in yraw]
    numeric_y = all(isinstance(v, float) for v in ynum)
    distinct = len(set(yraw))
    if task == 'auto':
        task = 'regression' if numeric_y and distinct > min(20, n // 10) else 'classification'
    if task == 'regression' and not numeric_y: raise SystemExit(f'"{target}" has values that are not numbers, so it cannot be predicted as a number. Choose classification.')
    if task == 'classification':
        counts = Counter(yraw)
        if len(counts) < 2: raise SystemExit(f'"{target}" has only one value; there is nothing to predict.')
        if len(counts) > 200: raise SystemExit(f'"{target}" has {len(counts)} different values — too many classes. Predict it as a number (regression) or group the values.')
        rare = [k for k, c in counts.items() if c < 2]
        if rare:
            rows = [r for r in rows if r[ti].strip() not in rare]; yraw = [r[ti].strip() for r in rows]; n = len(rows)
        y = np.array(yraw, dtype=object)
    else:
        y = np.array([float(v) for v in ynum])

    # --- the features
    features, dropped = [], []
    for i, h in enumerate(header):
        if i == ti: continue
        col = [r[i].strip() for r in rows]
        present = [v for v in col if v.lower() not in MISSING]
        if len(present) < n * 0.05: dropped.append({'column': h, 'why': 'mostly empty'}); continue
        vals = [num(v) for v in present]
        is_num = sum(isinstance(v, float) for v in vals) >= 0.95 * len(present)
        uniq = len(set(present))
        if uniq <= 1: dropped.append({'column': h, 'why': 'the same on every row'}); continue
        if is_num:
            ints = all(isinstance(v, float) and v.is_integer() for v in vals)
            if ints and uniq >= 0.98 * len(present) and len(present) > 50 and sorted(vals) == list(np.arange(min(vals), min(vals) + len(vals))):
                dropped.append({'column': h, 'why': 'a running number (identifier)'}); continue
            features.append({'name': h, 'type': 'number'})
        else:
            if uniq >= 0.9 * len(present) and len(present) > 50: dropped.append({'column': h, 'why': 'different on almost every row (identifier or free text)'}); continue
            if uniq > 1000: dropped.append({'column': h, 'why': f'{uniq} different values — too many categories'}); continue
            top = [v for v, _ in Counter(present).most_common(50)]
            features.append({'name': h, 'type': 'category', 'values': top})
    if not features: raise SystemExit('No column is usable as an input after removing identifiers, empty and constant columns.')
    idx = {h: i for i, h in enumerate(header)}
    X = np.empty((n, len(features)), dtype=object)
    for j, f in enumerate(features):
        c = idx[f['name']]
        for r in range(n):
            v = rows[r][c].strip()
            if f['type'] == 'number':
                x = num(v); X[r, j] = x if isinstance(x, float) else np.nan
            else:
                X[r, j] = v if v.lower() not in MISSING else np.nan
    numc = [j for j, f in enumerate(features) if f['type'] == 'number']
    catc = [j for j, f in enumerate(features) if f['type'] == 'category']
    say(phase='columns', features=len(features), dropped=len(dropped), task=task)

    def prep(scale):
        parts = []
        if numc: parts.append(('num', Pipeline([('imp', SimpleImputer(strategy='median'))] + ([('sc', StandardScaler())] if scale else [])), numc))
        if catc: parts.append(('cat', Pipeline([('imp', SimpleImputer(strategy='constant', fill_value='(missing)')),
                                                ('oh', OneHotEncoder(handle_unknown='infrequent_if_exist', min_frequency=5, max_categories=40, sparse_output=False))]), catc))
        return ColumnTransformer(parts)

    jobs = max(1, (os.cpu_count() or 2) - 2)
    if task == 'classification':
        cands = {'Logistic regression': LogisticRegression(max_iter=2000, class_weight='balanced'),
                 'Random forest': RandomForestClassifier(n_estimators=300, n_jobs=jobs, class_weight='balanced_subsample', random_state=42),
                 'Gradient boosting': HistGradientBoostingClassifier(random_state=42, class_weight='balanced')}
        cv, scoring = StratifiedKFold(5, shuffle=True, random_state=42), 'f1_macro'
        strat = y if min(Counter(y).values()) >= 2 else None
    else:
        cands = {'Ridge regression': Ridge(alpha=1.0),
                 'Random forest': RandomForestRegressor(n_estimators=300, n_jobs=jobs, random_state=42),
                 'Gradient boosting': HistGradientBoostingRegressor(random_state=42)}
        cv, scoring, strat = KFold(5, shuffle=True, random_state=42), 'r2', None
    Xtr, Xte, ytr, yte = train_test_split(X, y, test_size=0.2, random_state=42, stratify=strat)

    compare = []
    for name, est in cands.items():
        pipe = Pipeline([('prep', prep(name.startswith(('Logistic', 'Ridge')))), ('model', est)])
        t = time.time()
        try:
            s = cross_val_score(pipe, Xtr, ytr, cv=cv, scoring=scoring, n_jobs=1 if 'forest' in name else min(5, jobs))
            compare.append({'model': name, 'score': round(float(s.mean()), 4), 'spread': round(float(s.std()), 4), 'seconds': round(time.time() - t, 1)})
        except Exception as e:
            compare.append({'model': name, 'score': None, 'error': f'{type(e).__name__}: {str(e)[:150]}'})
        say(phase='comparing', model=name, score=compare[-1].get('score'))
    ok = [c for c in compare if c.get('score') is not None]
    if not ok: raise SystemExit('Every model failed: ' + '; '.join(c.get('error', '') for c in compare))
    best = max(ok, key=lambda c: c['score'])['model']
    pipe = Pipeline([('prep', prep(best.startswith(('Logistic', 'Ridge')))), ('model', cands[best])])
    pipe.fit(Xtr, ytr)
    pred = pipe.predict(Xte)
    m = {'task': task, 'model': best, 'scoring': 'macro-F1' if task == 'classification' else 'R²', 'compare': compare,
         'rows': n, 'train_rows': len(ytr), 'test_rows': len(yte), 'features': len(features), 'dropped': dropped}
    if task == 'classification':
        labels = sorted(set(y.tolist()), key=str)
        m.update(accuracy=round(float(accuracy_score(yte, pred)), 4), macro_f1=round(float(f1_score(yte, pred, average='macro')), 4),
                 baseline=round(max(Counter(yte.tolist()).values()) / len(yte), 4), labels=labels,
                 confusion=confusion_matrix(yte, pred, labels=labels).tolist() if len(labels) <= 20 else None)
    else:
        m.update(r2=round(float(r2_score(yte, pred)), 4), mae=round(float(mean_absolute_error(yte, pred)), 4),
                 rmse=round(float(math.sqrt(mean_squared_error(yte, pred))), 4), baseline_mae=round(float(np.mean(np.abs(yte - np.mean(ytr)))), 4),
                 target_range=[round(float(y.min()), 4), round(float(y.max()), 4)])
    say(phase='importance')
    sub = np.random.default_rng(1).choice(len(yte), min(2000, len(yte)), replace=False)
    pi = permutation_importance(pipe, Xte[sub], yte[sub], n_repeats=5, random_state=42, scoring=scoring, n_jobs=1)
    m['importance'] = sorted(({'column': f['name'], 'value': round(float(v), 4)} for f, v in zip(features, pi.importances_mean)), key=lambda d: -d['value'])

    say(phase='final fit')
    pipe.fit(X, y)
    os.makedirs(out, exist_ok=True)
    joblib.dump(pipe, os.path.join(out, 'model.joblib'), compress=3)
    example = {f['name']: (rows[0][idx[f['name']]].strip() or None) for f in features}
    for f in features:
        if f['type'] == 'number':
            col = X[:, features.index(f)].astype(float)
            f['min'], f['max'], f['median'] = [round(float(v), 4) for v in (np.nanmin(col), np.nanmax(col), np.nanmedian(col))]
    json.dump({'task': task, 'target': target, 'features': features, 'example': example, 'labels': m.get('labels')}, open(os.path.join(out, 'schema.json'), 'w'), ensure_ascii=False)
    m['seconds'] = round(time.time() - t0, 1)
    json.dump(m, open(os.path.join(out, 'metrics.json'), 'w'), ensure_ascii=False)
    say(phase='done', model=best, seconds=m['seconds'])


if __name__ == '__main__':
    main()
