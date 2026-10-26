//! Metering proxy: the tunnel points at this port instead of Ollama, so the app can count what AI Studio asks
//! this node to do. It copies bytes untouched and only reads along: the request line and model name from the
//! start of each request, and Ollama's `eval_count` / `prompt_eval_count` from the response.

use serde::Serialize;
use std::collections::BTreeMap;
use std::sync::{Arc, Mutex};
use std::time::Instant;
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::{TcpListener, TcpStream};

#[derive(Clone, Debug, Serialize)]
pub struct RequestRecord {
    pub at: i64,
    pub method: String,
    pub path: String,
    pub model: Option<String>,
    pub ms: u64,
    pub prompt_tokens: u64,
    pub output_tokens: u64,
    pub ok: bool,
}

#[derive(Default, Debug, Serialize, Clone)]
pub struct ModelTotals {
    pub requests: u64,
    pub prompt_tokens: u64,
    pub output_tokens: u64,
    pub ms: u64,
}

#[derive(Default)]
pub struct Meter {
    pub recent: Vec<RequestRecord>,
    pub by_model: BTreeMap<String, ModelTotals>,
    pub total_requests: u64,
    pub total_errors: u64,
}

pub type SharedMeter = Arc<Mutex<Meter>>;

impl Meter {
    fn record(&mut self, r: RequestRecord) {
        self.total_requests += 1;
        if !r.ok {
            self.total_errors += 1;
        }
        // Only model work goes in per-model totals; health probes (/api/version, /api/tags, /api/ps) don't.
        if let Some(m) = &r.model {
            let t = self.by_model.entry(m.clone()).or_default();
            t.requests += 1;
            t.prompt_tokens += r.prompt_tokens;
            t.output_tokens += r.output_tokens;
            t.ms += r.ms;
        }
        self.recent.push(r);
        let n = self.recent.len();
        if n > 2000 {
            self.recent.drain(0..n - 2000);
        }
    }
}

/// Latest machine metrics as JSON, refreshed every few seconds by the app. AI Studio reads it through the tunnel at
/// `GET /aistudio-node/metrics` for its Monitoring page.
pub type LiveMetrics = Arc<Mutex<String>>;
pub const METRICS_PATH: &str = "/aistudio-node/metrics";

pub async fn run(listen_port: u16, ollama: String, meter: SharedMeter, live: LiveMetrics) {
    let listener = match TcpListener::bind(("127.0.0.1", listen_port)).await {
        Ok(l) => l,
        Err(e) => {
            crate::config::log(&format!("proxy: cannot listen on 127.0.0.1:{listen_port}: {e}"));
            return;
        }
    };
    loop {
        let Ok((client, _)) = listener.accept().await else { continue };
        let ollama = ollama.clone();
        let meter = meter.clone();
        let live = live.clone();
        tokio::spawn(async move {
            let _ = handle(client, &ollama, meter, live).await;
        });
    }
}

/// One client connection. Requests on a keep-alive connection are metered from the first request's head;
/// AI Studio opens a connection per call, so that is the common case.
async fn handle(mut client: TcpStream, ollama: &str, meter: SharedMeter, live: LiveMetrics) -> std::io::Result<()> {
    let started = Instant::now();
    let mut first = vec![0u8; 16 * 1024];
    let n = client.read(&mut first).await?;
    if n == 0 {
        return Ok(());
    }
    first.truncate(n);
    let (method, path, model) = parse_head(&first);

    if method == "GET" && path == METRICS_PATH {
        let body = live.lock().map(|s| s.clone()).unwrap_or_default();
        let resp = format!(
            "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{}",
            body.len(),
            body
        );
        client.write_all(resp.as_bytes()).await?;
        return client.shutdown().await;
    }

    let mut upstream = TcpStream::connect(ollama).await?;
    upstream.write_all(&first).await?;

    let (mut cr, mut cw) = client.into_split();
    let (mut ur, mut uw) = upstream.into_split();
    let up = tokio::spawn(async move {
        let _ = tokio::io::copy(&mut cr, &mut uw).await;
        let _ = uw.shutdown().await;
    });

    // Copy the response back, scanning it for token counts and the status line.
    let mut buf = vec![0u8; 32 * 1024];
    let mut status_ok = None;
    let mut prompt_tokens = 0u64;
    let mut output_tokens = 0u64;
    let mut tail = Vec::<u8>::new();
    loop {
        let n = ur.read(&mut buf).await?;
        if n == 0 {
            break;
        }
        if status_ok.is_none() {
            status_ok = Some(buf.starts_with(b"HTTP/1.1 2") || buf.starts_with(b"HTTP/1.0 2"));
        }
        // Keep a little of the previous chunk so a number split across reads is still found.
        tail.extend_from_slice(&buf[..n]);
        if let Some(v) = last_number(&tail, b"\"eval_count\":") {
            output_tokens = v;
        }
        if let Some(v) = last_number(&tail, b"\"prompt_eval_count\":") {
            prompt_tokens = v;
        }
        let keep = tail.len().saturating_sub(64);
        tail.drain(..keep);
        cw.write_all(&buf[..n]).await?;
    }
    let _ = cw.shutdown().await;
    up.abort();

    let record = RequestRecord {
        at: chrono::Utc::now().timestamp(),
        method,
        path,
        model,
        ms: started.elapsed().as_millis() as u64,
        prompt_tokens,
        output_tokens,
        ok: status_ok.unwrap_or(false),
    };
    if let Ok(mut m) = meter.lock() {
        m.record(record);
    }
    Ok(())
}

fn parse_head(buf: &[u8]) -> (String, String, Option<String>) {
    let text = String::from_utf8_lossy(buf);
    let mut first = text.lines().next().unwrap_or("").split_whitespace();
    let method = first.next().unwrap_or("").to_string();
    let path = first.next().unwrap_or("").split('?').next().unwrap_or("").to_string();
    let model = text.split_once("\r\n\r\n").and_then(|(_, body)| {
        let i = body.find("\"model\"")?;
        let rest = &body[i + 7..];
        let rest = &rest[rest.find('"')? + 1..];
        Some(rest[..rest.find('"')?].to_string())
    });
    (method, path, model.filter(|m| !m.is_empty()))
}

fn last_number(hay: &[u8], key: &[u8]) -> Option<u64> {
    let pos = hay.windows(key.len()).rposition(|w| w == key)?;
    let digits: String = hay[pos + key.len()..]
        .iter()
        .skip_while(|b| **b == b' ')
        .take_while(|b| b.is_ascii_digit())
        .map(|b| *b as char)
        .collect();
    digits.parse().ok()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn reads_model_and_tokens() {
        let req = b"POST /api/chat HTTP/1.1\r\nHost: x\r\n\r\n{\"model\":\"helalm:4b\",\"messages\":[]}";
        let (m, p, model) = parse_head(req);
        assert_eq!((m.as_str(), p.as_str(), model.as_deref()), ("POST", "/api/chat", Some("helalm:4b")));
        assert_eq!(last_number(b"{\"done\":true,\"eval_count\": 42}", b"\"eval_count\":"), Some(42));
        assert_eq!(last_number(b"\"prompt_eval_count\":7,\"eval_count\":9", b"\"eval_count\":"), Some(9));
    }
}
