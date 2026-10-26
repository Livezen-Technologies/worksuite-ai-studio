//! AI Studio Node — desktop app that connects this computer to WorkSuite AI Studio as an Ollama node.
//!
//! Enrolment uses the same one-time tokens and server API as the aistudio-node CLI script
//! (POST /api/nodes/enrol). The app then keeps the reverse SSH tunnel up, meters the requests AI Studio sends
//! through it (proxy.rs) and shows live stats, history and settings.

mod config;
mod proxy;
mod stats;
mod tunnel;

use config::{Enrolment, Settings};
use serde::Serialize;
use std::sync::{Arc, Mutex};
use std::time::Duration;
use tauri::menu::{Menu, MenuItem};
use tauri::tray::{MouseButton, TrayIconBuilder, TrayIconEvent};
use tauri::{AppHandle, Manager, State, WindowEvent};

struct App {
    tunnel: tunnel::Tunnel,
    meter: proxy::SharedMeter,
    sampler: Mutex<stats::Sampler>,
    history: Arc<Mutex<Vec<stats::Sample>>>,
}

#[derive(Serialize)]
struct Overview {
    enrolment: Option<Enrolment>,
    settings: Settings,
    tunnel: tunnel::Status,
    machine: stats::Machine,
    ollama: stats::Ollama,
    requests: u64,
    errors: u64,
    tokens: u64,
}

#[tauri::command]
fn overview(app: State<App>) -> Overview {
    let settings = Settings::load();
    let machine = app.sampler.lock().unwrap().machine();
    let m = app.meter.lock().unwrap();
    Overview {
        enrolment: Enrolment::load(),
        tunnel: app.tunnel.status.lock().unwrap().clone(),
        machine,
        ollama: stats::ollama(&settings),
        settings,
        requests: m.total_requests,
        errors: m.total_errors,
        tokens: m.by_model.values().map(|t| t.prompt_tokens + t.output_tokens).sum(),
    }
}

#[derive(Serialize)]
struct Analytics {
    samples: Vec<stats::Sample>,
    by_model: std::collections::BTreeMap<String, proxy::ModelTotals>,
    recent: Vec<proxy::RequestRecord>,
}

#[tauri::command]
fn analytics(app: State<App>, range_secs: i64) -> Analytics {
    let cutoff = chrono::Utc::now().timestamp() - range_secs;
    let samples = app.history.lock().unwrap().iter().filter(|s| s.t >= cutoff).cloned().collect();
    let m = app.meter.lock().unwrap();
    Analytics {
        samples,
        by_model: m.by_model.clone(),
        recent: m.recent.iter().rev().filter(|r| r.model.is_some()).take(100).cloned().collect(),
    }
}

#[tauri::command]
fn enrol(app: State<App>, server: String, token: String, name: String) -> Result<Enrolment, String> {
    if Enrolment::load().is_some() {
        return Err("This computer is already connected. Disconnect it first.".into());
    }
    let server = server.trim().trim_end_matches('/').to_string();
    if !server.starts_with("https://") && !server.starts_with("http://") {
        return Err("The server address must start with https://".into());
    }
    let mut settings = Settings::load();
    settings.server = server.clone();
    settings.save().map_err(|e| e.to_string())?;

    let key = config::key_path();
    if !key.exists() {
        let host = sysinfo::System::host_name().unwrap_or_else(|| "node".into());
        let mut c = std::process::Command::new(tunnel::keygen_exe());
        c.args(["-q", "-t", "ed25519", "-N", "", "-C"]).arg(format!("aistudio-node@{host}")).arg("-f").arg(&key);
        tunnel::hide_window(&mut c);
        let out = c.output().map_err(|e| format!("Could not run ssh-keygen ({e}). Install the OpenSSH client."))?;
        if !out.status.success() {
            return Err(format!("ssh-keygen failed: {}", String::from_utf8_lossy(&out.stderr)));
        }
    }
    let public_key = std::fs::read_to_string(key.with_extension("pub")).map_err(|e| e.to_string())?;
    let public_key: String = public_key.split_whitespace().take(2).collect::<Vec<_>>().join(" ");

    let m = app.sampler.lock().unwrap().machine();
    let o = stats::ollama(&settings);
    if !o.up {
        return Err(format!("Ollama is not running at {}. Start Ollama (https://ollama.com/download) and try again.", settings.ollama_url));
    }
    let gpu = m.gpus.first().map(|g| format!("{}, {:.0} MiB", g.name, g.mem_total_mb)).unwrap_or_default();
    let body = serde_json::json!({
        "token": token.trim(), "public_key": public_key, "name": name.trim(), "hostname": m.hostname,
        "os": m.os, "gpu_model": gpu, "cuda_version": m.cuda.clone().unwrap_or_default(),
        "cpu_cores": m.cpu_cores, "ram_gb": m.ram_total_gb.round(), "ollama_version": o.version.unwrap_or_default(),
    });
    let resp = reqwest::blocking::Client::builder()
        .timeout(Duration::from_secs(30))
        .build()
        .map_err(|e| e.to_string())?
        .post(format!("{server}/api/nodes/enrol"))
        .json(&body)
        .send()
        .map_err(|e| format!("Could not reach {server}: {e}"))?;
    let status = resp.status();
    let v: serde_json::Value = resp.json().unwrap_or_default();
    if !status.is_success() {
        return Err(v["message"].as_str().unwrap_or("Enrolment failed.").to_string());
    }
    let d = &v["data"];
    if let Some(kh) = d["known_host"].as_str() {
        std::fs::write(config::known_hosts_path(), format!("{kh}\n")).map_err(|e| e.to_string())?;
    }
    let e = Enrolment {
        server,
        server_id: d["server_id"].to_string(),
        name: d["name"].as_str().unwrap_or_default().to_string(),
        tunnel_port: d["tunnel_port"].as_u64().ok_or("Unexpected answer from the server.")? as u16,
        ssh_target: format!("{}@{}", d["ssh_user"].as_str().unwrap_or("nodetunnel"), d["ssh_host"].as_str().unwrap_or_default()),
        ssh_port: d["ssh_port"].as_u64().unwrap_or(22) as u16,
    };
    e.save().map_err(|e| e.to_string())?;
    config::log(&format!("enrolled as {} (port {})", e.name, e.tunnel_port));
    app.tunnel.start();
    Ok(e)
}

/// Stop the tunnel and forget the enrolment here. The node must also be removed in AI Studio to revoke its key.
#[tauri::command]
fn disconnect(app: State<App>) -> Result<(), String> {
    app.tunnel.stop();
    Enrolment::forget().map_err(|e| e.to_string())?;
    config::log("disconnected");
    Ok(())
}

#[tauri::command]
fn set_tunnel(app: State<App>, on: bool) {
    if on {
        cli::take_over();
        app.tunnel.start();
    } else {
        app.tunnel.stop();
    }
}

#[tauri::command]
fn save_settings(app: State<App>, settings: Settings) -> Result<(), String> {
    let restart = Settings::load().proxy_port != settings.proxy_port;
    settings.save().map_err(|e| e.to_string())?;
    if restart {
        // The proxy keeps its first port until the app restarts; point the tunnel at the new one now.
        app.tunnel.stop();
        app.tunnel.start();
    }
    Ok(())
}

#[tauri::command]
fn read_log() -> String {
    let text = std::fs::read_to_string(config::log_path()).unwrap_or_default();
    let lines: Vec<&str> = text.lines().collect();
    lines[lines.len().saturating_sub(200)..].join("\n")
}

/// The CLI script (aistudio-node.sh / .ps1) may already run a tunnel for the same enrolment. Only one may
/// hold the server port, so the app stops the script's autostart entry and process before starting its own.
mod cli {
    use std::process::Command;

    pub fn take_over() {
        #[cfg(windows)]
        {
            let mut c = Command::new("schtasks");
            c.args(["/End", "/TN", "AIStudioNode"]);
            crate::tunnel::hide_window(&mut c);
            if c.output().map(|o| o.status.success()).unwrap_or(false) {
                let mut d = Command::new("schtasks");
                d.args(["/Delete", "/TN", "AIStudioNode", "/F"]);
                crate::tunnel::hide_window(&mut d);
                let _ = d.output();
                crate::config::log("stopped the CLI script's AIStudioNode task");
            }
        }
        #[cfg(target_os = "macos")]
        {
            let uid = Command::new("id").arg("-u").output().map(|o| String::from_utf8_lossy(&o.stdout).trim().to_string()).unwrap_or_default();
            let _ = Command::new("launchctl").args(["bootout", &format!("gui/{uid}/com.livezen.aistudio-node")]).output();
            if let Some(h) = dirs::home_dir() {
                let _ = std::fs::remove_file(h.join("Library/LaunchAgents/com.livezen.aistudio-node.plist"));
            }
        }
        #[cfg(target_os = "linux")]
        {
            let _ = Command::new("systemctl").args(["--user", "disable", "--now", "aistudio-node.service"]).output();
        }
        // Any tunnel the script left running (its command line names the key in ~/.aistudio-node).
        let me = std::process::id();
        let key = crate::config::key_path().display().to_string();
        let sys = sysinfo::System::new_all();
        for (pid, p) in sys.processes() {
            let cmd = p.cmd().iter().map(|s| s.to_string_lossy()).collect::<Vec<_>>().join(" ");
            let script = cmd.contains("tunnel.ps1") || cmd.contains("tunnel.sh");
            if pid.as_u32() != me && (script || (cmd.contains(&key) && cmd.contains("-R"))) && cmd.contains(".aistudio-node") {
                p.kill();
            }
        }
    }
}

fn show(app: &AppHandle) {
    if let Some(w) = app.get_webview_window("main") {
        let _ = w.show();
        let _ = w.unminimize();
        let _ = w.set_focus();
    }
}

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    let meter: proxy::SharedMeter = Default::default();
    let history = Arc::new(Mutex::new(stats::load_history()));

    tauri::Builder::default()
        .plugin(tauri_plugin_single_instance::init(|app, _, _| show(app)))
        .plugin(tauri_plugin_autostart::init(tauri_plugin_autostart::MacosLauncher::LaunchAgent, Some(vec!["--minimized"])))
        .plugin(tauri_plugin_opener::init())
        .manage(App {
            tunnel: tunnel::Tunnel::new(),
            meter: meter.clone(),
            sampler: Mutex::new(stats::Sampler::new()),
            history: history.clone(),
        })
        .invoke_handler(tauri::generate_handler![overview, analytics, enrol, disconnect, set_tunnel, save_settings, read_log])
        .setup(move |app| {
            let settings = Settings::load();
            config::log("app started");

            // Live metrics for AI Studio's Monitoring page, refreshed every 5 s.
            let live: proxy::LiveMetrics = Default::default();
            let live_w = live.clone();
            std::thread::spawn(move || {
                let mut sampler = stats::Sampler::new();
                loop {
                    let m = sampler.machine();
                    let g = m.gpus.first();
                    let pct = |a: f32, b: f32| if b > 0.0 { (a / b * 1000.0).round() / 10.0 } else { 0.0 };
                    let json = serde_json::json!({
                        "ts": chrono::Utc::now().timestamp(),
                        "cpu_pct": (m.cpu * 10.0).round() / 10.0,
                        "mem_pct": pct(m.ram_used_gb, m.ram_total_gb),
                        "disk_pct": pct(m.disk_total_gb - m.disk_free_gb, m.disk_total_gb),
                        "gpu_pct": g.map(|g| g.util),
                        "vram_pct": g.map(|g| pct(g.mem_used_mb, g.mem_total_mb)),
                        "gpu_temp_c": g.map(|g| g.temp_c),
                        "gpu_power_w": g.map(|g| g.power_w),
                        "cpu_cores": m.cpu_cores,
                        "ram_gb": (m.ram_total_gb * 10.0).round() / 10.0,
                        "gpus": m.gpus,
                    });
                    *live_w.lock().unwrap() = json.to_string();
                    std::thread::sleep(Duration::from_secs(5));
                }
            });

            // Metering proxy in front of Ollama.
            let (port, ollama, m) = (settings.proxy_port, settings.ollama_addr(), meter.clone());
            tauri::async_runtime::spawn(async move { proxy::run(port, ollama, m, live).await });

            if Enrolment::load().is_some() {
                cli::take_over();
                app.state::<App>().tunnel.start();
            }

            // History sampler for Analytics.
            let handle = app.handle().clone();
            std::thread::spawn(move || {
                let mut sampler = stats::Sampler::new();
                let (mut last_req, mut last_tok) = (0u64, 0u64);
                let mut n = 0u32;
                loop {
                    std::thread::sleep(Duration::from_secs(Settings::load().sample_seconds.max(10)));
                    let m = sampler.machine();
                    let state = handle.state::<App>();
                    let connected = state.tunnel.status.lock().unwrap().state == tunnel::State::Connected;
                    let (req, tok) = {
                        let mt = state.meter.lock().unwrap();
                        (mt.by_model.values().map(|t| t.requests).sum::<u64>(), mt.by_model.values().map(|t| t.prompt_tokens + t.output_tokens).sum::<u64>())
                    };
                    let g = m.gpus.first();
                    let sample = stats::Sample {
                        t: chrono::Utc::now().timestamp(),
                        cpu: m.cpu,
                        ram: if m.ram_total_gb > 0.0 { m.ram_used_gb / m.ram_total_gb * 100.0 } else { 0.0 },
                        gpu: g.map(|g| g.util).unwrap_or(0.0),
                        vram: g.filter(|g| g.mem_total_mb > 0.0).map(|g| g.mem_used_mb / g.mem_total_mb * 100.0).unwrap_or(0.0),
                        connected,
                        requests: req - last_req,
                        tokens: tok - last_tok,
                    };
                    (last_req, last_tok) = (req, tok);
                    let mut h = history.lock().unwrap();
                    h.push(sample);
                    n += 1;
                    if n % 5 == 0 {
                        stats::trim_history(&mut h);
                        stats::save_history(&h);
                    }
                }
            });

            // Tray: the node keeps running when the window is closed.
            let open = MenuItem::with_id(app, "open", "Open AI Studio Node", true, None::<&str>)?;
            let quit = MenuItem::with_id(app, "quit", "Quit (disconnects this node)", true, None::<&str>)?;
            let menu = Menu::with_items(app, &[&open, &quit])?;
            TrayIconBuilder::with_id("tray")
                .icon(app.default_window_icon().unwrap().clone())
                .tooltip("AI Studio Node")
                .menu(&menu)
                .show_menu_on_left_click(false)
                .on_menu_event(|app, e| match e.id.as_ref() {
                    "open" => show(app),
                    "quit" => {
                        app.state::<App>().tunnel.stop();
                        stats::save_history(&app.state::<App>().history.lock().unwrap());
                        app.exit(0);
                    }
                    _ => {}
                })
                .on_tray_icon_event(|tray, e| {
                    if let TrayIconEvent::Click { button: MouseButton::Left, .. } = e {
                        show(tray.app_handle());
                    }
                })
                .build(app)?;

            if settings.start_minimized || std::env::args().any(|a| a == "--minimized") {
                if let Some(w) = app.get_webview_window("main") {
                    let _ = w.hide();
                }
            }
            Ok(())
        })
        .on_window_event(|w, e| {
            if let WindowEvent::CloseRequested { api, .. } = e {
                if Settings::load().close_to_tray {
                    api.prevent_close();
                    let _ = w.hide();
                }
            }
        })
        .run(tauri::generate_context!())
        .expect("error while running AI Studio Node");
}
