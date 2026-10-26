//! Live machine stats (CPU, RAM, disk, NVIDIA GPU) and Ollama state, plus the sampled history behind Analytics.

use crate::config::{self, Settings};
use serde::{Deserialize, Serialize};
use std::process::Command;
use std::time::Duration;
use sysinfo::{Disks, System};

#[derive(Clone, Debug, Default, Serialize)]
pub struct Gpu {
    pub name: String,
    pub util: f32,
    pub mem_used_mb: f32,
    pub mem_total_mb: f32,
    pub temp_c: f32,
    pub power_w: f32,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct Machine {
    pub hostname: String,
    pub os: String,
    pub cpu_name: String,
    pub cpu_cores: usize,
    pub cpu: f32,
    pub ram_used_gb: f32,
    pub ram_total_gb: f32,
    pub disk_free_gb: f32,
    pub disk_total_gb: f32,
    pub gpus: Vec<Gpu>,
    pub cuda: Option<String>,
}

pub struct Sampler {
    sys: System,
}

impl Sampler {
    pub fn new() -> Sampler {
        let mut sys = System::new();
        sys.refresh_cpu_all();
        sys.refresh_memory();
        Sampler { sys }
    }

    pub fn machine(&mut self) -> Machine {
        self.sys.refresh_cpu_all();
        self.sys.refresh_memory();
        let disks = Disks::new_with_refreshed_list();
        // The disk holding the home folder (where Ollama keeps models by default).
        let home = dirs::home_dir().unwrap_or_default();
        let disk = disks
            .list()
            .iter()
            .filter(|d| home.starts_with(d.mount_point()))
            .max_by_key(|d| d.mount_point().as_os_str().len());
        let gb = |b: u64| b as f32 / 1_073_741_824.0;
        let (gpus, cuda) = nvidia();
        Machine {
            hostname: System::host_name().unwrap_or_default(),
            os: System::long_os_version().unwrap_or_default(),
            cpu_name: self.sys.cpus().first().map(|c| c.brand().trim().to_string()).unwrap_or_default(),
            cpu_cores: self.sys.cpus().len(),
            cpu: self.sys.global_cpu_usage(),
            ram_used_gb: gb(self.sys.used_memory()),
            ram_total_gb: gb(self.sys.total_memory()),
            disk_free_gb: disk.map(|d| gb(d.available_space())).unwrap_or(0.0),
            disk_total_gb: disk.map(|d| gb(d.total_space())).unwrap_or(0.0),
            gpus,
            cuda,
        }
    }
}

fn nvidia() -> (Vec<Gpu>, Option<String>) {
    let mut c = Command::new("nvidia-smi");
    c.args(["--query-gpu=name,utilization.gpu,memory.used,memory.total,temperature.gpu,power.draw", "--format=csv,noheader,nounits"]);
    crate::tunnel::hide_window(&mut c);
    let Ok(out) = c.output() else { return (vec![], None) };
    if !out.status.success() {
        return (vec![], None);
    }
    let num = |s: &str| s.trim().parse::<f32>().unwrap_or(0.0);
    let gpus = String::from_utf8_lossy(&out.stdout)
        .lines()
        .filter_map(|l| {
            let f: Vec<&str> = l.split(',').collect();
            (f.len() >= 6).then(|| Gpu {
                name: f[0].trim().into(),
                util: num(f[1]),
                mem_used_mb: num(f[2]),
                mem_total_mb: num(f[3]),
                temp_c: num(f[4]),
                power_w: num(f[5]),
            })
        })
        .collect();
    let mut c = Command::new("nvidia-smi");
    crate::tunnel::hide_window(&mut c);
    let cuda = c.output().ok().and_then(|o| {
        let t = String::from_utf8_lossy(&o.stdout).to_string();
        let i = t.find("CUDA Version:")?;
        Some(t[i + 13..].trim_start().split_whitespace().next()?.to_string())
    });
    (gpus, cuda)
}

#[derive(Clone, Debug, Default, Serialize, Deserialize)]
pub struct OllamaModel {
    pub name: String,
    #[serde(default)]
    pub size: u64,
    #[serde(default)]
    pub size_vram: u64,
    #[serde(default)]
    pub expires_at: Option<String>,
    #[serde(default)]
    pub details: Option<serde_json::Value>,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct Ollama {
    pub up: bool,
    pub version: Option<String>,
    pub models: Vec<OllamaModel>,
    pub running: Vec<OllamaModel>,
}

pub fn ollama(settings: &Settings) -> Ollama {
    let client = reqwest::blocking::Client::builder().timeout(Duration::from_secs(3)).build().unwrap();
    let get = |p: &str| client.get(format!("{}{}", settings.ollama_url.trim_end_matches('/'), p)).send().ok()?.json::<serde_json::Value>().ok();
    let Some(v) = get("/api/version") else { return Ollama::default() };
    let list = |v: Option<serde_json::Value>| -> Vec<OllamaModel> {
        v.and_then(|v| serde_json::from_value(v["models"].clone()).ok()).unwrap_or_default()
    };
    Ollama {
        up: true,
        version: v["version"].as_str().map(String::from),
        models: list(get("/api/tags")),
        running: list(get("/api/ps")),
    }
}

/// One history sample, every `sample_seconds`.
#[derive(Clone, Debug, Serialize, Deserialize)]
pub struct Sample {
    pub t: i64,
    pub cpu: f32,
    pub ram: f32,
    pub gpu: f32,
    pub vram: f32,
    pub connected: bool,
    pub requests: u64,
    pub tokens: u64,
}

const KEEP_SECS: i64 = 7 * 24 * 3600;

pub fn load_history() -> Vec<Sample> {
    std::fs::read_to_string(config::dir().join("history.json"))
        .ok()
        .and_then(|s| serde_json::from_str(&s).ok())
        .unwrap_or_default()
}

pub fn save_history(h: &[Sample]) {
    if let Ok(s) = serde_json::to_string(h) {
        let _ = std::fs::write(config::dir().join("history.json"), s);
    }
}

pub fn trim_history(h: &mut Vec<Sample>) {
    let cutoff = chrono::Utc::now().timestamp() - KEEP_SECS;
    h.retain(|s| s.t >= cutoff);
}
