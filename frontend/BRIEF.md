# Agent brief — rebuilding an AI Studio module screen

Repo: /Users/macbook/LHCP-deploys/worksuite-ai-studio (CodeIgniter 4 backend in app/, new React frontend in frontend/).
The original React source is lost; only the compiled bundle remains at public/app/assets/index-PiqQyBk9.js
(plus lazy chunks in public/app/assets/). The backend API is unchanged and is the contract.

## Your job
Replace the placeholder page(s) you are assigned in frontend/src/pages/ with a complete, polished screen that keeps
EVERY feature the old screen had, in the new design.

1. Read the backend controller(s) for your module in app/Controllers/Api/ (+ any Service/Model they call) to learn
   the exact request fields, response shapes, validation, permissions (`requirePermission('x.y')`) and edge cases.
   Routes: app/Config/Routes.php. Every JSON answer is `{status, message, data}`; `api()` returns `data`.
2. Recover the old screen's behaviour from the compiled bundle: grep public/app/assets/*.js for your route's strings
   and labels (python slicing around a distinctive string works well) — columns, filters, form fields, buttons,
   confirmations, tabs. Do not drop features. Improve wording and layout freely.
3. Build it with ONLY the shared kit:
   - `import { ... } from '../components/ui'` — Button, IconButton, Badge, StatusBadge, Card, CardHeader, StatCard,
     DescriptionList, Meter, Spinner, Skeleton, SkeletonRows, EmptyState, ErrorState, Alert, Input, Textarea, Select,
     Toggle, Checkbox, Field, FormSection, Modal, FormModal, ConfirmDialog, useDisclosure, ActionMenu, PageHeader,
     Toolbar, SearchInput, Tabs, Segmented, CodeBlock, DataTable (+Column type), Pagination, useToast, cx.
     Read frontend/src/components/ui/*.tsx first for the props.
   - Data: `@tanstack/react-query` (useQuery/useMutation, invalidate on success) + `frontend/src/lib/api.ts`
     (get/post/put/del/api, streamNdjson for NDJSON streams, download for file downloads, ApiError with `.fields`
     for 422 field errors). Formatting: `frontend/src/lib/format.ts` (num, compact, bytes, dateTime, ago, ms).
     Permissions: `useAuth().can('perm.slug')` — hide/disable actions the user may not take.
   - Icons: @heroicons/react (24/outline for UI, 20/solid for small). Charts: recharts v3 (theme-aware: use
     currentColor / slate tokens, readable in dark mode).
   - Page layout: `<PageHeader title description actions/>` then Cards. Lists = Card > Toolbar > DataTable > Pagination.
     Every list has loading skeleton, error-with-retry and an empty state with a call to action. Every mutation shows
     a toast (success or the server's message) and a busy state; destructive actions go through ConfirmDialog.
     Forms show server field errors from ApiError.fields. Mobile: hide minor columns with `hideBelow`.
   - Dark mode: every colour you add must have a `dark:` counterpart. Tailwind v4 utilities only.
4. Keep files tidy: one default-exported page per assigned file; put large sub-parts in
   frontend/src/pages/<Module>/*.tsx if needed.

## Rules
- Edit ONLY your assigned page files (and your own pages/<Module>/ folder). Do NOT edit components/ui, lib/, layout/,
  App.tsx, package.json, or other modules' pages; if the kit lacks something, build it locally in your folder.
- Do not run npm install, git commands, builds into public/, or anything against the live server.
- Verify with `cd frontend && npx tsc -b --force` — it must report no errors in YOUR files (ignore others' in-progress
  files only if they are clearly not yours).
- Final answer: the list of features you kept (old → new), anything you could not keep and why, and any kit gap.
