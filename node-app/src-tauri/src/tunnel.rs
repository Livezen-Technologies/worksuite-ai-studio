//! The reverse SSH tunnel: server 127.0.0.1:<tunnel_port> → this machine's metering proxy. Uses the system
//! OpenSSH client (built into Windows 10+, macOS and Linux desktops) and restarts it whenever it exits.

use crate::config::{self, Enrolment, Settings};
use serde::Serialize;
use std::process::{Child, Command, Stdio};
use std::sync::{Arc, Mutex};
use std::thread;
use std::time::{Duration, Instant};

#[derive(Clone, Debug, Serialize, PartialEq)]
#[serde(rename_all = "snake_case")]
pub enum State {
    Stopped,
    Connecting,
    Connected,
    Retrying,
}

#[derive(Clone, Debug, Serialize)]
pub struct Status {
    pub state: State,
    pub since: i64,
    pub reconnects: u64,
    pub last_error: Option<String>,
    /// Seconds connected since the app started, for the uptime figure.
    pub connected_secs: u64,
    pub started_at: i64,
}

pub struct Tunnel {
    pub status: Arc<Mutex<Status>>,
    want: Arc<Mutex<bool>>,
    child: Arc<Mutex<Option<Child>>>,
}

fn now() -> i64 { chrono::Utc::now().timestamp() }

impl Tunnel {
    pub fn new() -> Tunnel {
        Tunnel {
            status: Arc::new(Mutex::new(Status {
                state: State::Stopped,
                since: now(),
                reconnects: 0,
                last_error: None,
                connected_secs: 0,
                started_at: now(),
            })),
            want: Arc::new(Mutex::new(false)),
            child: Arc::new(Mutex::new(None)),
        }
    }

    pub fn start(&self) {
        {
            let mut w = self.want.lock().unwrap();
            if *w {
                return;
            }
            *w = true;
        }
        let (status, want, child) = (self.status.clone(), self.want.clone(), self.child.clone());
        thread::spawn(move || {
            let mut first = true;
            while *want.lock().unwrap() {
                let Some(e) = Enrolment::load() else {
                    set(&status, State::Stopped, Some("This computer is not connected to AI Studio.".into()));
                    *want.lock().unwrap() = false;
                    break;
                };
                if !first {
                    status.lock().unwrap().reconnects += 1;
                }
                first = false;
                set(&status, State::Connecting, None);
                let settings = Settings::load();
                let spawned = ssh_command(&e, settings.proxy_port).spawn();
                let mut proc = match spawned {
                    Ok(p) => p,
                    Err(err) => {
                        set(&status, State::Retrying, Some(format!("Could not start ssh: {err}. Install the OpenSSH client.")));
                        sleep_while(&want, 15);
                        continue;
                    }
                };
                let mut stderr = proc.stderr.take();
                *child.lock().unwrap() = Some(proc);

                // ssh -N prints nothing on success; alive after a few seconds with ExitOnForwardFailure means up.
                let began = Instant::now();
                let mut marked = false;
                let mut last_tick = Instant::now();
                loop {
                    let exited = {
                        let mut c = child.lock().unwrap();
                        match c.as_mut() {
                            Some(p) => p.try_wait().ok().flatten().map(|s| s.code()),
                            None => Some(None),
                        }
                    };
                    if let Some(code) = exited {
                        let mut err = String::new();
                        if let Some(s) = stderr.as_mut() {
                            use std::io::Read;
                            let _ = s.read_to_string(&mut err);
                        }
                        let err = err.lines().filter(|l| !l.trim().is_empty()).last().unwrap_or("").to_string();
                        config::log(&format!("tunnel exited ({code:?}): {err}"));
                        *child.lock().unwrap() = None;
                        if *want.lock().unwrap() {
                            set(&status, State::Retrying, Some(explain(&err)));
                        }
                        break;
                    }
                    if !marked && began.elapsed() > Duration::from_secs(4) {
                        set(&status, State::Connected, None);
                        config::log("tunnel connected");
                        marked = true;
                    }
                    if marked {
                        status.lock().unwrap().connected_secs += last_tick.elapsed().as_secs();
                    }
                    last_tick = Instant::now();
                    thread::sleep(Duration::from_secs(1));
                }
                sleep_while(&want, 15);
            }
            let err = status.lock().unwrap().last_error.clone();
            set(&status, State::Stopped, err);
        });
    }

    pub fn stop(&self) {
        *self.want.lock().unwrap() = false;
        if let Some(mut p) = self.child.lock().unwrap().take() {
            let _ = p.kill();
            let _ = p.wait();
        }
        set(&self.status, State::Stopped, None);
    }
}

fn set(status: &Arc<Mutex<Status>>, state: State, err: Option<String>) {
    let mut s = status.lock().unwrap();
    if s.state != state {
        s.since = now();
    }
    s.state = state;
    s.last_error = err;
}

fn sleep_while(want: &Arc<Mutex<bool>>, secs: u64) {
    for _ in 0..secs {
        if !*want.lock().unwrap() {
            return;
        }
        thread::sleep(Duration::from_secs(1));
    }
}

fn explain(err: &str) -> String {
    if err.contains("Permission denied") {
        "The server refused this computer's key. It may have been removed in AI Studio — disconnect and connect again.".into()
    } else if err.contains("remote port forwarding failed") {
        "The server port is still held by an earlier connection (another copy of the node, or the CLI script). Retrying.".into()
    } else if err.contains("Host key verification failed") {
        "The server's identity did not match the one saved at enrolment. Not connecting.".into()
    } else if err.is_empty() {
        "Connection lost. Retrying.".into()
    } else {
        err.to_string()
    }
}

pub fn ssh_command(e: &Enrolment, local_port: u16) -> Command {
    let mut c = Command::new(ssh_exe());
    c.arg("-N")
        .arg("-i").arg(config::key_path())
        .arg("-p").arg(e.ssh_port.to_string())
        .args(["-o", "BatchMode=yes", "-o", "IdentitiesOnly=yes", "-o", "ExitOnForwardFailure=yes"])
        .args(["-o", "ServerAliveInterval=30", "-o", "ServerAliveCountMax=3", "-o", "StrictHostKeyChecking=yes"])
        .arg("-o").arg(format!("UserKnownHostsFile={}", config::known_hosts_path().display()))
        .arg("-R").arg(format!("127.0.0.1:{}:127.0.0.1:{}", e.tunnel_port, local_port))
        .arg(&e.ssh_target)
        .stdin(Stdio::null())
        .stdout(Stdio::null())
        .stderr(Stdio::piped());
    hide_window(&mut c);
    c
}

pub fn ssh_exe() -> String {
    #[cfg(windows)]
    {
        let sys = std::env::var("WINDIR").unwrap_or_else(|_| "C:\\Windows".into());
        let p = format!("{sys}\\System32\\OpenSSH\\ssh.exe");
        if std::path::Path::new(&p).exists() {
            return p;
        }
    }
    "ssh".into()
}

pub fn keygen_exe() -> String {
    #[cfg(windows)]
    {
        let sys = std::env::var("WINDIR").unwrap_or_else(|_| "C:\\Windows".into());
        let p = format!("{sys}\\System32\\OpenSSH\\ssh-keygen.exe");
        if std::path::Path::new(&p).exists() {
            return p;
        }
    }
    "ssh-keygen".into()
}

#[cfg(windows)]
pub fn hide_window(c: &mut Command) {
    use std::os::windows::process::CommandExt;
    c.creation_flags(0x0800_0000); // CREATE_NO_WINDOW
}
#[cfg(not(windows))]
pub fn hide_window(_c: &mut Command) {}
