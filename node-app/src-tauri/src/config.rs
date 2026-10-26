//! Files in ~/.aistudio-node — the same folder the aistudio-node CLI script uses, so the app can take over a
//! machine the script enrolled (and the other way round).
//!
//!   id_ed25519 / .pub   SSH key for the tunnel
//!   known_hosts         the Studio server's pinned host key
//!   node.env            enrolment: SERVER, SERVER_ID, NAME, TUNNEL_PORT, SSH_TARGET, SSH_PORT
//!   settings.json       app settings
//!   history.json        sampled stats for the Analytics page

use serde::{Deserialize, Serialize};
use std::collections::BTreeMap;
use std::fs;
use std::path::PathBuf;

pub fn dir() -> PathBuf {
    let d = dirs::home_dir().unwrap_or_else(|| PathBuf::from(".")).join(".aistudio-node");
    let _ = fs::create_dir_all(&d);
    d
}

pub fn key_path() -> PathBuf { dir().join("id_ed25519") }
pub fn known_hosts_path() -> PathBuf { dir().join("known_hosts") }
pub fn log_path() -> PathBuf { dir().join("app.log") }

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(default)]
pub struct Settings {
    pub server: String,
    /// Local port the tunnel points at: the app's metering proxy in front of Ollama.
    pub proxy_port: u16,
    pub ollama_url: String,
    /// How often a point is added to the Analytics history.
    pub sample_seconds: u64,
    pub start_minimized: bool,
    pub close_to_tray: bool,
}

impl Default for Settings {
    fn default() -> Self {
        Settings {
            server: "https://aistudio.livezentech.com".into(),
            proxy_port: 11439,
            ollama_url: "http://127.0.0.1:11434".into(),
            sample_seconds: 60,
            start_minimized: false,
            close_to_tray: true,
        }
    }
}

impl Settings {
    pub fn load() -> Settings {
        fs::read_to_string(dir().join("settings.json"))
            .ok()
            .and_then(|s| serde_json::from_str(&s).ok())
            .unwrap_or_default()
    }
    pub fn save(&self) -> std::io::Result<()> {
        fs::write(dir().join("settings.json"), serde_json::to_string_pretty(self)?)
    }
    /// host:port of Ollama, for the proxy.
    pub fn ollama_addr(&self) -> String {
        self.ollama_url.trim_start_matches("http://").trim_end_matches('/').to_string()
    }
}

#[derive(Clone, Debug, Serialize)]
pub struct Enrolment {
    pub server: String,
    pub server_id: String,
    pub name: String,
    pub tunnel_port: u16,
    pub ssh_target: String,
    pub ssh_port: u16,
}

impl Enrolment {
    pub fn load() -> Option<Enrolment> {
        let text = fs::read_to_string(dir().join("node.env")).ok()?;
        let kv: BTreeMap<String, String> = text
            .lines()
            .filter_map(|l| l.split_once('='))
            .map(|(k, v)| (k.trim().to_string(), v.trim().to_string()))
            .collect();
        Some(Enrolment {
            server: kv.get("SERVER")?.clone(),
            server_id: kv.get("SERVER_ID").cloned().unwrap_or_default(),
            name: kv.get("NAME").cloned().unwrap_or_default(),
            tunnel_port: kv.get("TUNNEL_PORT")?.parse().ok()?,
            ssh_target: kv.get("SSH_TARGET")?.clone(),
            ssh_port: kv.get("SSH_PORT").and_then(|p| p.parse().ok()).unwrap_or(22),
        })
    }
    pub fn save(&self) -> std::io::Result<()> {
        fs::write(
            dir().join("node.env"),
            format!(
                "SERVER={}\nSERVER_ID={}\nNAME={}\nTUNNEL_PORT={}\nSSH_TARGET={}\nSSH_PORT={}\n",
                self.server, self.server_id, self.name, self.tunnel_port, self.ssh_target, self.ssh_port
            ),
        )
    }
    /// Forget the enrolment locally. The key stays so the folder can be inspected; Studio still has to remove the node.
    pub fn forget() -> std::io::Result<()> {
        let _ = fs::remove_file(dir().join("known_hosts"));
        fs::remove_file(dir().join("node.env"))
    }
}

pub fn log(msg: &str) {
    use std::io::Write;
    let line = format!("{} {}\n", chrono::Local::now().format("%F %T"), msg);
    // Keep the log small: start over past 1 MB.
    if fs::metadata(log_path()).map(|m| m.len() > 1_000_000).unwrap_or(false) {
        let _ = fs::remove_file(log_path());
    }
    if let Ok(mut f) = fs::OpenOptions::new().create(true).append(true).open(log_path()) {
        let _ = f.write_all(line.as_bytes());
    }
}
