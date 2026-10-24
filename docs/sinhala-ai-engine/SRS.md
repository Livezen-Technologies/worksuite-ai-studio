Software Requirements Specification

Version 1.0 • 24 October 2026

An offline first Sinhala language platform for WorkSuite Desktop,
Office, Meet, FrameFlow and OS services.

This specification defines the behavior, interfaces, data controls and
release evidence required to implement a shared local AI engine. It
covers speech recognition, Sinhala speech synthesis, captions, Singlish
input, legacy encoding conversion, writing assistance, translation and
the lifecycle of custom models. Product, engineering, language
specialists, quality assurance and deployment teams use the requirement
identifiers as the implementation and acceptance baseline.

The engine shall keep document and audio processing on the device by
default. Ollama serves the Qwen3 text model; faster-whisper serves
speech recognition in a separate worker. Sinhala TTS uses a separately
licensed voice package. Deterministic language tools remain available
when generative models are unloaded or unavailable.

**Document status:** Proposed engineering baseline. Requirements using
“shall” are normative. Architecture choices and default values are the
proposed implementation baseline; performance and language quality
thresholds are release targets requiring measured evidence.

| **Control**     | **Value**                                                                                        |
|-----------------|--------------------------------------------------------------------------------------------------|
| Product owner   | WorkSuite product lead                                                                           |
| Technical owner | AI engine lead                                                                                   |
| Quality owners  | QA lead and Sinhala language lead                                                                |
| Security owner  | WorkSuite security lead                                                                          |
| Approval record | Version, approver, role, date and disposition recorded in the release repository                 |
| Change control  | Every behavioral change updates affected requirement IDs, API version, tests and migration notes |

# Reader guide

Sections 1–4 establish scope and architecture. Sections 5–10 define
functional behavior. Sections 11–14 define workflows, contracts and
storage. Sections 15–20 define operational constraints, training,
evaluation, security, deployment and testing. Sections 21–24 define
delivery gates, risks, acceptance and references.

# 1 Purpose and scope

## 1.1 Product outcomes

WorkSuite users shall be able to write, dictate, read aloud, translate
and publish Sinhala content without an Internet connection after
provisioning. The same engine shall supply consistent results and model
versions to each WorkSuite client. Administrators shall install approved
models, evaluate replacements and recover a working version without
exposing private content.

Success means that a user can install an offline bundle, dictate a
paragraph into Office, correct it, translate a selection, generate audio
and create captions in FrameFlow while external networking is blocked.
Quality failures shall be visible and recoverable; generated text shall
require review before it changes a document or becomes published media.

## 1.2 Included capabilities

The scope includes local setup and diagnostics; streaming and file ASR;
single speaker Sinhala TTS; editable SRT and WebVTT captions;
bidirectional Singlish transliteration; explicit Unicode to legacy
encoding conversion and reverse conversion; word and phrase suggestions;
spelling and grammar review; Sinhala–English translation; optional phase
3 Sinhala–Tamil translation; consent based personalization; training and
evaluation pipelines; model package management; SDKs and five WorkSuite
adapters.

Caption burn in and bilingual subtitle tracks are phase 2 features.
Speaker diarization is phase 3 and requires its own approved model and
quality gate. ASR alone does not identify speakers. Training operates in
a separate controlled environment, not in the end user desktop service.

## 1.3 Exclusions

Initial releases exclude foundation model pretraining, autonomous
document submission, arbitrary system command execution, always on
recording, identity recognition from voice, voice cloning, cloud
inference, unrestricted third party plugins, OCR and automatic repair of
scanned documents. Font conversion does not recover text from images.
WorkSuite OS means an integration surface, not a commitment to build an
operating system.

## 1.4 Assumptions and decisions

The WorkSuite host applications will expose selection, document
revision, undo and media timeline APIs. Their contracts in this document
are proposed adapter interfaces; existing product APIs must be mapped
during phase 0. No undocumented host behavior is required for core
engine acceptance.

The initial distribution targets Windows 11 x64, macOS 14 or later on
Apple Silicon, and Ubuntu 24.04 x64. Exact supported point releases,
runtime versions and drivers shall be frozen in the release
compatibility manifest. Linux ARM and Intel macOS are outside the
initial certified matrix.

A Sinhala language lead approves dictionaries, normalization rules,
voice pronunciation and evaluation references. Product management
approves register and terminology choices. An enabled feature must pass
its quality gate; the UI shall show unavailable capability status if the
selected package fails readiness checks.

## 1.5 Priorities and release semantics

P0 means required for a safe, usable initial release. P1 means required
for the complete product release, normally phase 2. P2 means a later
enhancement, normally phase 3. P0 controls apply to every enabled
feature even if that feature ships later. A feature cannot be called
supported until its functional and quality acceptance gates pass. MUST
and shall are equivalent; should indicates a recommendation.

# 2 Personas and permissions

| **Persona**                 | **Primary work**                               | **Required access**                                  |
|-----------------------------|------------------------------------------------|------------------------------------------------------|
| Sinhala author              | Dictation, correction, translation in Office   | Own documents, selected context, personal dictionary |
| Student or educator         | Read aloud and prepare learning material       | Own text and approved voice packages                 |
| Meeting participant or host | Live captions and reviewed notes               | Explicitly authorized session audio and transcript   |
| Media creator               | Time aligned captions and narration            | Selected FrameFlow media and caption tracks          |
| Accessibility user          | Keyboard input, suggestions, speech playback   | User enabled OS input and audio capabilities         |
| Organization administrator  | Install, activate, revoke and roll back models | Scoped package and policy management                 |
| Language reviewer           | Annotate and score approved examples           | Approved dataset partitions only                     |
| ML engineer                 | Train, evaluate and package candidates         | Training workspace and approved datasets             |
| Support operator            | Inspect health and sanitized diagnostics       | No document, prompt or recording access by default   |

A local user may manage personal packages if organization policy
permits. Administrator privileges shall not automatically grant document
access. Training and production identities shall remain separate.

# 3 System architecture

## 3.1 Logical components

The baseline is one service instance per signed in OS user. WorkSuite
clients communicate with a local gateway through a shared SDK. The
gateway enforces authorization, limits, capability negotiation and job
ownership. A durable scheduler routes work to isolated ASR, TTS and text
workers. Rules, dictionary, transliteration and encoding conversion run
without an LLM. The model registry supplies immutable package references
to each job.

Desktop / Office / Meet / FrameFlow / OS adapters

\|

Authenticated local SDK

\|

Gateway + policy + revision checks + job scheduler

\| \| \| \|

Language tools Qwen3 worker ASR worker TTS worker

\| Ollama faster- licensed

\| whisper voice runtime

+------------------+--------------------+

\|

SQLite metadata + encrypted artifacts + model registry

\|

Admin UI + offline package importer + sanitized diagnostics

Separate build and training zone

Approved data -\> training -\> evaluation -\> signed packages

## 3.2 Component decisions

Use a typed Python gateway and worker ecosystem with a versioned JSON
API; a native supervisor handles process lifecycle, credential storage
and local transport. A TypeScript SDK serves desktop and editor clients,
with a small native bridge for OS input. These are baseline choices that
may change through an architecture decision record if observable
requirements remain satisfied.

Ollama is the inference runtime for Qwen3, not the ASR, TTS or training
runtime. Begin evaluation with Qwen3 4B and 8B quantized candidates;
prefer 4B on constrained devices and 8B on standard devices only after
Sinhala benchmarks pass. Each package pins an immutable artifact digest
rather than relying on a mutable tag. Do not infer adequate Sinhala
performance from multilingual model claims. \[S1, S2\]

faster-whisper executes Whisper compatible checkpoints through
CTranslate2. Use multilingual small as a constrained baseline and
evaluate medium or large-v3 on accelerated hardware. English only
checkpoints are ineligible. Streaming shall be implemented with audio
windows, VAD and transcript reconciliation; faster-whisper is not
assumed to supply a complete live meeting transport. \[S3\]

The TTS runtime shall accept only a Sinhala voice with verified
redistribution and commercial usage permissions for the intended
product. A VITS based model trained on licensed recordings is the
baseline development path. Meta MMS is a research comparison candidate;
its model collection lists CC BY NC 4.0 and shall not be bundled
commercially without separately verified permission. No unverified
per-language checkpoint is a production dependency. \[S4\]

## 3.3 Resource routing and failure isolation

The scheduler shall reserve live ASR ahead of interactive text, TTS and
batch exports, in that order. Use one GPU intensive job at a time by
default. Queue batch work while Meet is active. Rules and converter
calls have an independent CPU pool. Memory admission shall account for
model weights, context cache, audio buffers and host application
headroom.

An ASR worker crash shall terminate or resume only its jobs and leave
transliteration, font conversion and text tools usable. A watchdog shall
restart a failing worker at most three times in five minutes before
opening a circuit breaker. In flight jobs pin their package version;
activation changes affect new jobs only.

## 3.4 Trust boundaries

Treat document text, subtitles, audio metadata, model output, imported
dictionaries and model packages as untrusted input. Generated text
cannot invoke tools, read arbitrary files, install packages or expand
the host selection. The gateway is the only supported client boundary.
Ollama and worker ports shall never be exposed to WorkSuite web content
or the LAN in the standard deployment.

# 4 Shared functional requirements

**FR CORE 001 • P0 • Offline operation.** The engine shall execute every
installed supported capability without DNS, login, license refresh or
external API calls. Missing packages shall return MODEL_UNAVAILABLE with
local installation guidance; the engine shall not download or switch to
cloud inference automatically. Acceptance: AT01.

**FR CORE 002 • P0 • Capability discovery.** The gateway shall expose
supported tasks, language directions, active package IDs, device class,
degraded state, limits and API versions. Clients shall disable only
unavailable functions and explain the reason. Acceptance: AT02.

**FR CORE 003 • P0 • Durable jobs.** The engine shall create owned jobs,
report progress, cancel jobs, persist terminal status and recover
interrupted batch work. Jobs shall follow the state machine in section
12. Acceptance: AT03.

**FR CORE 004 • P0 • Reproducibility.** Every result shall identify
engine version, model digest, dictionary or mapping version, input
revision and effective generation parameters. Exact generative byte
equality across devices is not required; deterministic converter output
shall be identical. Acceptance: AT04.

**FR CORE 005 • P0 • User control.** Clients shall preview edits,
preserve the original and apply only accepted changes through a single
undo transaction. Stale revisions shall fail without changing content.
Acceptance: AT05.

**FR CORE 006 • P0 • Local profiles.** Profiles shall store language,
device, dictionary, retention and accessibility preferences per OS user.
Shared computer users shall have isolated storage and credentials.
Acceptance: AT06.

**FR CORE 007 • P0 • Diagnostics.** The service shall expose readiness,
worker health, queue depth, disk usage and package compatibility without
content. Exporting diagnostics shall require user initiation and a
preview of included fields. Acceptance: AT07.

**FR CORE 008 • P1 • Batch processing.** Users shall submit ordered
batches, pause dispatch, resume and retry failed items independently.
Successful items shall not be repeated or overwritten when a batch is
retried. Acceptance: AT03 and AT18.

# 5 Speech recognition requirements

**FR ASR 001 • P0 • Local input.** The engine shall accept microphone
PCM and imported WAV, FLAC, MP3, M4A or MP4 audio after decoder
validation. Internal audio shall be mono 16 kHz PCM for ASR; retain
source timeline and channel metadata. Corrupt or unsupported media shall
fail with a stable error. Acceptance: AT08.

**FR ASR 002 • P0 • Sinhala and mixed speech.** Users shall choose si,
en or auto detection. Sinhala recognition shall support English words
within Sinhala speech, retaining proper names and numerals rather than
silently translating them. Auto detection shall expose detected language
and uncertainty. Accuracy is governed by section 17. Acceptance: AT09.

**FR ASR 003 • P0 • Live transcript.** The engine shall emit revisable
partial segments and immutable final segments with IDs, timestamps and
revision counters. Partial text shall not be committed to the document
until the client or user accepts it. The UI shall distinguish partials
visually and through accessibility state. Acceptance: AT10.

**FR ASR 004 • P0 • VAD and silence.** VAD shall suppress silence
transcription; configurable pre roll and post roll shall preserve
clipped word boundaries. The worker shall clear inappropriate previous
text conditioning after long silence. Silence fixtures shall not
generate fabricated sentences. Acceptance: AT09.

**FR ASR 005 • P0 • Timing and provenance.** Final segments shall
include source-relative start and end milliseconds, language and
nullable quality scores. Word timings are optional and shall be labeled
approximate. Raw ASR confidence shall not be presented as calibrated
accuracy. Acceptance: AT08 and AT11.

**FR ASR 006 • P0 • Recovery.** A live stream shall acknowledge sequence
numbers, detect missing frames and emit an explicit gap event. After a
disconnect, the client may replay only unacknowledged frames within a 30
second buffer. Final segments shall not duplicate. Lost audio shall not
be invented. Acceptance: AT10.

**FR ASR 007 • P1 • Vocabulary and review.** Users shall supply a
bounded approved glossary of up to 500 terms and correct transcripts.
Raw recognition, punctuation cleanup and user edits shall remain
distinguishable. Glossary hints do not guarantee recognition and shall
not be inserted when absent from speech. Acceptance: AT09.

**FR ASR 008 • P2 • Diarization.** An approved separate component may
assign anonymous speaker labels with manual reassignment. The UI shall
allow unknown and overlapping speech. No real identity shall be
inferred. Acceptance: AT26.

## 5.1 Live recognition baseline

Capture 20 ms audio frames. Send binary batches of at most 200 ms, each
with a monotonically increasing sequence number. Decode rolling windows
of up to 20 seconds with 1 second overlap; tune window policy in phase 0
and freeze it per ASR profile. Reconcile overlap using timestamps and
stable token spans. Endpoint after approximately 700 ms silence; only
the service finalization event makes a segment immutable. Report the
algorithm version in provenance.

On buffer exhaustion, emit backpressure and stop accepting frames rather
than dropping audio silently. The client shall show an interruption
banner, let the user resume and insert a visible gap marker. File jobs
shall checkpoint at segment boundaries with enough overlap to reconcile
the next chunk after restart.

# 6 Sinhala text to speech requirements

**FR TTS 001 • P0 • Approved voice.** The engine shall synthesize
Sinhala text with an installed approved voice. Voice metadata shall
declare language, speaker consent reference, license, sample rate,
runtime compatibility and quality report. No approved voice means TTS is
unavailable. Acceptance: AT12 and AT21.

**FR TTS 002 • P0 • Normalization.** A versioned front end shall expand
dates, times, numbers, currency and approved abbreviations according to
locale. Users shall preview ambiguous readings and override
pronunciation using a local lexicon. English spans shall use an explicit
fallback policy and warning when unsupported. Acceptance: AT12.

**FR TTS 003 • P0 • Playback and export.** Users shall preview, pause,
stop and export WAV locally. Cancellation shall stop audio playback and
worker generation. Narration export shall preserve sentence boundaries
and return duration and source text revision. Acceptance: AT12.

**FR TTS 004 • P1 • Controls and alignment.** The engine shall expose
only controls supported by the selected voice, such as rate within its
validated range. Word highlighting shall be offered only when alignment
exists, and approximate timing shall be labeled. MP3 export requires an
approved encoder package. Acceptance: AT12 and AT18.

**FR TTS 005 • P0 • Voice protection.** The engine shall provide no
voice cloning endpoint. Voice packages shall include consent and usage
constraints; revoked packages shall be blocked for new generation.
Existing user exports shall remain user files and shall not be silently
deleted. Acceptance: AT21.

# 7 Caption generation requirements

**FR CAP 001 • P0 • Caption project.** The engine shall derive an
editable caption track from reviewed or raw ASR output and preserve
linkage to media time. Captions shall store source transcript revision
and whether review is complete. Acceptance: AT11.

**FR CAP 002 • P0 • Segmentation.** The default profile shall use at
most two lines, target at most 42 grapheme clusters per line and 17
grapheme clusters per second, and cue durations from 1 to 7 seconds.
Language approved line breaks shall avoid splitting Sinhala grapheme
clusters. Infeasible cues shall be flagged for review rather than
silently deleting words. These are product defaults subject to usability
validation. Acceptance: AT11.

**FR CAP 003 • P0 • Editing.** Users shall split, merge, retime and edit
cues, resolve overlaps and search text. Every operation shall be
reversible; changing transcript text shall mark derived captions stale
rather than overwrite manual edits. Acceptance: AT11.

**FR CAP 004 • P0 • Export.** The engine shall export UTF-8 SRT and
WebVTT with monotonic nonnegative timing, valid format delimiters and no
accidental HTML or control markup. Output shall round trip through the
WorkSuite importer with text and timings preserved within 1 ms.
Acceptance: AT11.

**FR CAP 005 • P1 • Translation tracks.** The engine shall create a
separate translated caption track and keep source cue links. Translation
expansion shall trigger resegmentation and reading speed checks without
moving beyond media duration. Acceptance: AT15.

**FR CAP 006 • P1 • Burn in.** FrameFlow shall render approved Unicode
Sinhala fonts with correct shaping, safe margins and deterministic
styling. Users shall preview representative frames; missing glyphs shall
block export. Burn in shall use a separately packaged media renderer and
shall not be assumed to come from faster-whisper. Acceptance: AT18.

## 7.1 Timing contract

Use integer milliseconds relative to the selected media asset. FrameFlow
adapters shall convert between rational frame time and milliseconds only
at the boundary and preserve original rational timestamps in project
metadata. Cue intervals are half open \[start_ms, end_ms). Cues in a
single standard track shall not overlap; explicitly supported speaker
overlap requires a separate profile. Importing translated cues shall not
replace original audio or subtitles.

# 8 Singlish and encoding conversion requirements

**FR TRN 001 • P0 • Singlish input.** A deterministic transliteration
engine shall map Latin input to ranked Sinhala candidates using a
versioned rule table and lexicon. It shall support partial syllables,
long vowels, ambiguous consonants and user selected alternatives. Input
composition shall remain reversible until commit. Acceptance: AT13.

**FR TRN 002 • P0 • Mixed input.** Users shall toggle Sinhala
composition, literal Latin text and temporary bypass. URLs, email
addresses, code and protected spans shall remain unchanged. A candidate
shall not replace text until an explicit commit key or user selection.
Acceptance: AT13.

**FR TRN 003 • P1 • Reverse and personalization.** The engine shall
generate a labeled romanization of Sinhala and learn local candidate
rankings only with opt in. Reverse output shall identify its scheme and
shall not imply a unique reversible spelling. Users shall reset learned
rankings and export personal dictionaries. Acceptance: AT13 and AT06.

**FR FNT 001 • P0 • Explicit mapping.** Users shall choose Unicode to
legacy or legacy to Unicode and an exact encoding profile. Initial
candidate profiles are FM Abhaya and DL Manike, but each shall ship only
with validated mapping fixtures and distribution rights. A font family
name alone shall not determine source encoding. Acceptance: AT14.

**FR FNT 002 • P0 • Deterministic conversion.** A versioned mapping
engine shall handle multi-code-point Sinhala sequences, joiners, vowel
reordering, punctuation and escaped Latin spans. It shall report every
unmapped or ambiguous sequence with source offsets. LLM generation shall
not perform encoding conversion. Acceptance: AT14.

**FR FNT 003 • P0 • Loss controls.** Conversion shall preview before and
after, keep the original and report lossless, lossy or ambiguous status.
Reverse conversion shall not promise exact recovery for many to one
mappings. Unsupported sequences shall fail strict mode or remain
unchanged with warnings in permissive mode. Acceptance: AT14.

**FR FNT 004 • P1 • Rich document conversion.** Office shall convert
selected text runs using explicit source encoding metadata, preserving
paragraph and inline styles where supported. Latin runs shall not be
converted solely because a legacy font is applied. Each transformed run
shall have a source map for undo and diagnostics. Acceptance: AT17.

## 8.1 Unicode contract

Canonical application storage is UTF-8 Sinhala Unicode in NFC. Preserve
meaningful ZWJ and ZWNJ sequences and do not globally strip joiners.
Normalization is a separate operation from legacy conversion. Keep the
original string and a source map if normalization changes offsets. \[S5,
S6\]

APIs use zero based UTF-16 code unit offsets over the exact supplied
input for editor compatibility. All ranges are half open; returned
ranges shall begin and end on extended grapheme boundaries. The engine
shall maintain mappings between raw input, normalized internal text and
output. Reject unpaired surrogates. Pin the Unicode segmentation library
version in every release; apply Sinhala language reviewed tailorings
through a separately versioned profile. \[S5\]

# 9 Writing assistance and translation requirements

**FR WR 001 • P0 • Word suggestions.** The engine shall provide up to
five prefix and context candidates using local lexicons and ranking. It
shall exclude protected spans and return the request input revision. A
stale result shall be discarded. Acceptance: AT16.

**FR WR 002 • P1 • Phrase suggestions.** An optional Qwen3 path shall
suggest short continuations in the chosen register. It shall have an
explicit on or off control, bounded context and a cancel mechanism.
Phrase generation shall not block deterministic word suggestions.
Acceptance: AT16.

**FR WR 003 • P0 • Spelling.** The checker shall distinguish recognized
words, likely errors and unknown names. Each issue shall contain a
range, explanation, alternatives and a rule or model source. Users shall
ignore once, ignore for document or add to a personal dictionary.
Acceptance: AT16.

**FR WR 004 • P1 • Grammar.** The engine shall propose minimal
corrections with explanations and register labels, preserve intended
meaning, and flag uncertainty. It shall never apply all generated
changes automatically. Contradictory or overlapping edits shall be
resolved or presented as alternatives. Acceptance: AT16.

**FR WR 005 • P0 • Protected content.** Spell, grammar and rewriting
shall preserve names, numbers, dates, URLs, formulas, code and user
locked spans unless the user explicitly targets them. Numeric changes
shall appear in the diff and require separate confirmation within the
edit preview. Acceptance: AT16 and AT05.

**FR WR 006 • P1 • Register and glossary.** Users shall choose formal,
neutral or conversational Sinhala and attach a local domain glossary.
The result shall identify unsupported register requests and glossary
conflicts. Dictionaries shall not be silently shared between users.
Acceptance: AT16.

**FR MT 001 • P1 • Sinhala and English.** The engine shall translate si
to en and en to si while preserving paragraph structure, names, numeric
values and protected placeholders. Output shall include source language,
target language, model version and review warnings. Acceptance: AT15.

**FR MT 002 • P1 • Long documents.** The gateway shall segment by
document structure and model token budget, preserve segment IDs and
reassemble in order. It shall never silently truncate. Glossary context
may span segments within the approved input boundary; failed segments
remain visibly untranslated. Acceptance: AT15.

**FR MT 003 • P1 • Review and alignment.** Clients shall present source
and target side by side with segment links, edits and accept or reject
controls. Back translation may assist review but shall not be
represented as evidence of correctness. Acceptance: AT15.

**FR MT 004 • P2 • Tamil direction.** Sinhala–Tamil translation shall
remain capability disabled until separate bilingual references, model
licenses and per-direction quality gates pass. English scores cannot
qualify this direction. Acceptance: AT15 with Tamil benchmark extension.

## 9.1 Text model behavior

Qwen3 prompts shall clearly delimit user content as data and request
schema bounded outputs. Low latency writing paths shall request non
thinking generation where the pinned model supports it. Raw reasoning
traces shall not enter client text. The worker shall validate structure,
ranges, protected placeholders and output length before returning a
result. One bounded format repair is allowed; subsequent failure returns
OUTPUT_INVALID. No repair can expand the selection or change a protected
numeric field. \[S2\]

Do not expose model generated confidence as a probability. Issues may
include an empirically calibrated score only when the calibration
dataset and method are versioned. Otherwise use labels such as review
recommended. Transliteration, lexicon lookup and exact encoding mapping
remain separate from generative suggestions.

# 10 Model administration requirements

**FR ADM 001 • P0 • Inventory.** The admin manager shall list installed
and active packages by task, language, digest, size, license status,
compatibility, evaluation status and last activation. Model licenses and
attribution shall be accessible offline. Acceptance: AT21.

**FR ADM 002 • P0 • Safe import.** An administrator shall import a
signed bundle from an authorized file or explicitly start a download
from an approved registry. The importer shall check signatures, hashes,
archive paths, expanded size, runtime compatibility, license approval
and free space before staging. Acceptance: AT21.

**FR ADM 003 • P0 • Activation.** Activation shall require smoke tests
and a qualifying evaluation report, atomically update a task and profile
binding, and retain the previous approved version. Untrusted or revoked
packages shall not activate. In flight work retains its pinned version.
Acceptance: AT22.

**FR ADM 004 • P0 • Rollback and removal.** Admins shall roll back to a
compatible retained version and remove inactive unreferenced packages.
Removing an active or in use package shall return CONFLICT. Shared blobs
shall use reference counting and shall not be deleted while referenced.
Acceptance: AT22.

**FR ADM 005 • P0 • Policy and audit.** Organization policy shall
control install permissions, approved registries, cloud prohibition,
retention maxima and supported task bindings. Policy changes shall be
authenticated and audited with actor, action, result and package digest,
excluding content. Acceptance: AT20 and AT21.

**FR ADM 006 • P1 • Device profiles.** The manager shall recommend a
local package profile from measured memory and compute capability, show
disk and memory estimates, and offer a smaller approved model on
admission failure. Changing profile shall require user acceptance and
preserve job provenance. Acceptance: AT23.

**FR ADM 007 • P1 • Fleet export.** Admins shall export a signed policy
and package manifest for offline device provisioning and import
sanitized fleet health reports. This shall not export recordings,
documents, personal dictionaries or training samples. Acceptance: AT21.

## 10.1 Model package lifecycle

Candidate → quarantined → verified → evaluated → approved → staged →
active → retired. Any state may become revoked by an authenticated local
policy update. Offline revocation information is limited to the last
imported signed list, whose age shall be displayed. Stale revocation
data shall be governed by organization policy and shall not create a
hidden Internet dependency.

Package signatures establish publisher integrity, not model quality.
Approval additionally requires a task specific evaluation report,
compatible runtime test and documented distribution permission. Only
trusted publisher keys can authorize executable runtime extensions;
standard model bundles shall contain data and declarative configuration
only.

# 11 WorkSuite integrations

## 11.1 Adapter contract

Every adapter shall implement getCapabilities(), getSelection(),
getRevision(), previewEdits(), applyEdits(expectedRevision, edits,
undoLabel) and subscribeToJob(). Media adapters additionally implement
resolveAsset(), getTimelineTimebase() and applyCaptionTrack(). Adapters
shall pass only the selected context and use opaque document and asset
references. The engine shall never scrape the full screen or enumerate
host files to obtain context.

**FR INT 001 • P0 • Desktop.** Desktop shall host setup, model
availability, dictation, TTS playback, recent owned jobs and
diagnostics. It shall start the per user service on demand and show a
clear unavailable state when startup fails. Acceptance: AT17.

**FR INT 002 • P0 • Office.** Office shall expose selection based spell
check, transliteration, font conversion and dictation through the SDK.
It shall compare document revision at apply time, preserve rich text
outside changed runs and commit accepted changes as one undo action.
Phase 2 adds grammar and translation. Acceptance: AT05 and AT17.

**FR INT 003 • P1 • Meet.** Meet shall require an explicit start action,
show recording and caption indicators to the host UI, capture only
permitted audio sources and stop capture on meeting exit. It shall label
machine captions and allow reviewed transcript export. Consent
notification wording and participant controls shall be finalized for the
deployment context before release. Acceptance: AT10, AT17 and AT20.

**FR INT 004 • P1 • FrameFlow.** FrameFlow shall create caption and
narration tracks linked to source assets, preserve timebase and manual
edits, and preview Sinhala shaping before export. It shall not overwrite
the source media. Acceptance: AT11 and AT18.

**FR INT 005 • P1 • OS.** The OS adapter shall provide user enabled
Sinhala input composition, a dictation shortcut and selected text read
aloud using platform supported permissions. It shall never capture
password or secure input fields, and shall disable itself when a field
is known to be secure or its security state cannot be established.
Acceptance: AT19.

**FR INT 006 • P0 • Compatibility.** Clients shall negotiate an API
major version and feature flags. Unsupported clients shall receive
INCOMPATIBLE_CLIENT with required version information, while supported
functions continue on other clients. Acceptance: AT02.

## 11.2 Selection and revision rules

A text request includes document_id, input_revision, selected text,
selection offsets and explicitly permitted surrounding context. The host
retains authority over edits. The SDK shall check the returned input
hash and revision, then call the host apply method. If the user types
while a suggestion is running, the client may request new suggestions;
it shall not remap stale offsets speculatively.

Revision IDs are opaque strings, not wall clock timestamps. A result
references exactly one revision. A host that lacks transactional undo
shall expose preview and copy only until an adapter can satisfy the
editing contract.

# 12 End to end workflows

## WF01 Offline provisioning and first use

The admin verifies installer signature, installs the per user service
and imports the model bundle. The engine validates package manifests,
licenses, hashes, runtime and disk space. It stages packages, runs a
synthetic readiness test, activates approved task bindings and reports
capabilities. With external networking blocked, the user selects a
language and creates a small dictation and conversion job. A failed
package leaves existing packages active and shows the actionable
failure. Requirements: CORE 001–007, ADM 001–005; tests AT01, AT02,
AT21, AT22.

## WF02 Dictate and correct in Office

The user opens an editable document, grants microphone access and starts
dictation. Office sends audio frames and displays partial text in a
composition overlay. Final text enters a preview buffer; the user
commits it against the captured revision. The user reviews spelling
issues, accepts selected replacements and can undo the entire operation.
Permission denial, microphone removal or a stream gap stops capture
visibly and preserves accepted text. Requirements: ASR 001–007, WR
003–005, INT 002; tests AT05, AT08–10, AT16, AT17.

## WF03 Create captions and narration

The creator selects a media asset in FrameFlow. An import job decodes
audio and produces transcript segments. The creator reviews text,
generates caption cues, resolves speed warnings and optionally creates a
translated track. A narration request uses the approved text revision
and voice. Preview shows shaped Sinhala text and timed audio. Export
writes new artifacts atomically. Failed export preserves the caption
project and original media. Requirements: CAP 001–006, TTS 001–004, MT
001–003, INT 004; tests AT11, AT12, AT15, AT18.

## WF04 Convert a legacy document

The author selects text runs, chooses the source encoding profile and
previews detected ambiguous spans. Strict conversion either produces a
fully mapped result or returns issues without editing the document. The
author can select permissive conversion, review unchanged spans and
apply against the original revision. Unsupported font names never
trigger guessed destructive changes. Requirements: FNT 001–004; tests
AT14, AT17.

## WF05 Train and promote a custom model

The data steward approves rights, consent and dataset scope. The ML
engineer creates immutable data partitions, trains a candidate and
records lineage. Independent language reviewers score the locked test
set. The release owner compares quality, fairness, memory, latency and
privacy results with the baseline. A passing package is signed,
imported, staged and activated. A failed gate retains the baseline;
rollback uses the previous binding. Requirements: TRNPIPE 001–006, ADM
002–005; tests AT21, AT22, AT24, AT25.

## WF06 Delete private session content

The user selects a session and sees all linked recordings, transcripts,
captions, caches and pending exports. Deletion cancels active jobs,
removes indexed content and destroys relevant artifact encryption keys
within 60 seconds. Audit metadata records a content free deletion event.
Independent exports and backups are disclosed as separate copies and
follow their own retention policy. Requirements: SEC 004–006; test AT20.

## 12.1 Job state and failure rules

Created → queued → running → succeeded or failed. Created, queued and
running may move to cancelling → cancelled. A pause request applies only
to dispatch or checkpointable batch work; live streams stop rather than
pause indefinitely. Terminal states are immutable. Retrying creates a
new job with retry_of set to the failed or cancelled job ID.

On service restart, running batch jobs become interrupted and can resume
from a validated checkpoint or fail with RECOVERY_REQUIRED. Interrupted
is a recoverable nonterminal state that transitions to queued or failed.
Live jobs become failed with STREAM_INTERRUPTED, preserving completed
final segments. A cancellation race is resolved transactionally: a
result committed before cancellation returns succeeded; after
cancellation acknowledgement, no new output can be committed. Partial
results are explicitly marked incomplete.

# 13 API contract

## 13.1 Transport and common rules

The normative client API is WorkSuite /v1, not Ollama’s native /api
surface. Prefer a Unix domain socket on macOS and Linux or a named pipe
with user ACLs on Windows. A loopback HTTP bridge may bind 127.0.0.1 on
an OS allocated port for clients that require it. The supervisor
supplies the endpoint and credentials through authenticated native IPC,
never through document content or URL parameters.

Every request requires a per installation client credential scoped to
the OS user, application and operation. Tokens are stored in the OS
credential vault and rotated at service restart or revocation. HTTP uses
Authorization: Bearer. WebSocket clients use the SDK native header
transport or an authenticated native proxy; no token goes in a query
string. Exact Origin and Host allowlists, no wildcard CORS, and local
peer verification apply. Browser accessible loopback endpoints shall
additionally enforce CSRF protection for state changes.

Use UTF-8 JSON, UUIDs for entity IDs, RFC 3339 UTC timestamps and
integer milliseconds for media. The server derives principal_id from
credentials and shall not trust a submitted owner field. Unknown fields
are rejected on mutation endpoints. JSON schemas use
additionalProperties: false. Responses expose request_id for support.
Collection endpoints use opaque cursor pagination, default 50 and
maximum 200 results.

A job is bound to an immutable package digest and document revision
before entering queued. POST mutations accept Idempotency-Key scoped to
principal, route and body hash for 24 hours; same key and same body
returns the original result, changed body returns 409. Request IDs are
correlation identifiers, not authorization credentials.

## 13.2 Endpoint catalog

| **Method**   | **Path**                    | **Contract and scope**                                                  |
|--------------|-----------------------------|-------------------------------------------------------------------------|
| GET          | /v1/health                  | Liveness only; no sensitive metadata                                    |
| GET          | /v1/capabilities            | Readiness, limits and version; engine.read                              |
| POST         | /v1/assets                  | Stream approved upload; assets.write                                    |
| GET / DELETE | /v1/assets/{id}             | Metadata or owned asset deletion; assets.read or write                  |
| GET          | /v1/assets/{id}/content     | Authorized binary content; assets.read                                  |
| POST         | /v1/text/transliterate      | Synchronous candidates; text.process                                    |
| POST         | /v1/text/convert            | Synchronous encoding result; text.process                               |
| POST         | /v1/text/suggest            | Synchronous dictionary candidates; text.process                         |
| POST         | /v1/jobs                    | ASR, TTS, captions, spell, grammar, translation or phrases; task scoped |
| GET          | /v1/jobs/{id}               | Owned job status and result references; jobs.read                       |
| POST         | /v1/jobs/{id}/cancel        | Idempotent cancellation; jobs.write                                     |
| GET          | /v1/jobs/{id}/events        | Server sent progress events with replay; jobs.read                      |
| WS           | /v1/asr/streams             | Live PCM and transcript events; asr.stream                              |
| POST         | /v1/caption-projects        | Create from transcript result; captions.write                           |
| PATCH        | /v1/caption-projects/{id}   | Cues and revision under If-Match; captions.write                        |
| GET          | /v1/models                  | Approved inventory; engine.read                                         |
| POST         | /v1/admin/packages/import   | Import an uploaded signed package; models.manage                        |
| POST         | /v1/admin/bindings/activate | Atomically activate package; models.manage                              |
| POST         | /v1/admin/bindings/rollback | Restore previous approved binding; models.manage                        |
| DELETE       | /v1/admin/packages/{id}     | Remove inactive unreferenced package; models.manage                     |
| GET / PUT    | /v1/preferences             | Read or update own validated preferences; profile.manage                |
| GET / PUT    | /v1/dictionaries/{id}       | Owned dictionary with ETag; dictionary.read or write                    |
| POST         | /v1/privacy/deletions       | Delete session and derived content; privacy.manage                      |
| GET / PUT    | /v1/admin/policy            | Versioned policy with If-Match; policy.manage                           |

GET jobs returns 200; creating an asynchronous job returns 202 with
Location and job ID. Synchronous tools return 200. Creating an asset or
caption project returns 201. Empty successful deletions return 204.
Admin package import and activation return asynchronous job references
and require fresh scoped authorization. An admin role does not bypass
asset ownership.

## 13.3 Limits and errors

Default JSON request limit is 1 MiB. Text inputs allow 50,000 UTF-16
units for synchronous spelling jobs and 10,000 for conversion or
transliteration; long text is segmented through jobs up to 1 million
units. Suggestions accept at most 2,000 surrounding units. The model
context limit is independently checked with the pinned tokenizer; input
plus reserved output must fit. Default Qwen3 profile context is 8,192
tokens, with up to 1,024 output tokens and profile specific templates
included in the budget. Oversize requests return 413 without truncation.

Uploads stream directly to encrypted staging, default maximum 2 GiB per
asset and four hours decoded audio. Time and decoded size limits prevent
decompression attacks. Live streams allow one per principal; the
standard profile supports one live stream and one foreground text job
with serialization if needed. At most eight queued jobs per principal
and 32 per service are admitted; overflow returns 429 with Retry-After.
These limits appear in capabilities and may be reduced by policy.

| **HTTP**  | **Error code**                         | **Required behavior**                                   |
|-----------|----------------------------------------|---------------------------------------------------------|
| 400 / 422 | INVALID_INPUT / UNSUPPORTED_FORMAT     | Identify invalid field without echoing private text     |
| 401 / 403 | UNAUTHENTICATED / FORBIDDEN            | Deny without executing a worker                         |
| 404       | NOT_FOUND                              | Same response for absent and other user owned resources |
| 409 / 412 | CONFLICT / STALE_REVISION              | Preserve content and request refresh                    |
| 413 / 429 | INPUT_TOO_LARGE / RESOURCE_BUSY        | Explain limit or bounded retry                          |
| 503       | MODEL_UNAVAILABLE / WORKER_UNAVAILABLE | Keep other capabilities running                         |
| 500       | OUTPUT_INVALID / INTERNAL_ERROR        | Opaque request ID; no stack traces or prompt echo       |

Error shape: {"error":{"code":"STALE_REVISION","message":"Refresh the
selection","retryable":false,"request_id":"uuid","details":{"field":"input_revision"}}}.
Runtime OOM is reported as RESOURCE_EXHAUSTED, with no automatic
reprocessing under a different model. A user may explicitly retry with
an approved smaller profile.

# 14 Schemas and persistent data

## 14.1 Common request and result schemas

The following JSON and field constraints define the baseline wire
contract. The implementation shall publish equivalent OpenAPI 3.1 and
JSON Schema files and pass contract tests against these examples before
SDK release. Strings are bounded as stated in section 13; all
identifiers and asset references are checked for ownership.

{

"task": "translate",

"document": {"id": "uuid", "input_revision": "r42"},

"input": {"text": "Selected text", "source_language": "si"},

"options": {

"target_language": "en", "register": "neutral",

"glossary_id": null, "protected_ranges": \[\]

},

"profile": "standard", "retention": "session"

}

JobRequest.task enum: transcribe, synthesize, captions, spell, grammar,
translate, phrases. document is nullable for media and standalone tasks.
profile is an approved profile ID rather than an arbitrary model URL.
retention enum is session or saved; policy may force session. Each task
uses a discriminated input and options schema rather than accepting
arbitrary option keys.

| **Task**        | **Required input**                | **Validated options and output**                                                   |
|-----------------|-----------------------------------|------------------------------------------------------------------------------------|
| transcribe      | asset_id                          | language si/en/auto, glossary_id, word_timestamps boolean; Transcript result       |
| synthesize      | text, language si                 | voice_id, supported rate, pronunciation_lexicon_id, WAV output; asset and duration |
| captions        | transcript_job_id, media_asset_id | profile_id, source_revision; cue array and warnings                                |
| spell / grammar | text, source_language si          | register, dictionary_id, protected_ranges; issue and edit arrays                   |
| translate       | text, source_language si/en       | target_language, register, glossary_id, protected_ranges; linked segments          |
| phrases         | text, source_language si          | caret_utf16, register, max_candidates 1–5; candidate array                         |

A completed job returns the common envelope below. Large results are
immutable JSON assets retrieved through the authorized content endpoint;
inline results are limited to 256 KiB. input_sha256 hashes exact UTF-8
input bytes after surrogate validation and before normalization. Input
hashes remain local and are not exported as telemetry.

{

"job_id": "uuid", "state": "succeeded", "progress": 1.0,

"input_revision": "r42", "input_sha256": "hex64",

"result": {"asset_id": "uuid", "kind": "translation"},

"warnings": \[\],

"provenance": {

"engine_version": "1.0.0", "package_id": "uuid",

"model_sha256": "hex64", "rules_version": "si-rules-1",

"parameters": {"seed": 42, "temperature": 0.2}

},

"created_at": "2026-10-24T00:00:00Z"

}

## 14.2 Text operations and edits

TransliterateRequest contains text, direction (latin_to_si or
si_to_latin), scheme_id, input_revision and max_candidates (1–5). Its
response contains candidates \[{text, rank, source}\], consumed_range
and input_revision. rank is ordinal, not a calibrated probability.

ConvertRequest contains text, source_encoding, target_encoding,
mapping_version, mode (strict or permissive) and input_revision.
ConvertResult contains text, status (lossless, lossy or ambiguous),
issues, source_map, mapping_version and input_revision. strict mode
returns 422 with unmapped spans and no converted result when fidelity
cannot be guaranteed.

SuggestRequest contains text, caret_utf16, source_language,
dictionary_id, max_candidates and input_revision. It returns
replacement_range, candidates and input_revision. Replacement range
cannot cross protected boundaries. Synchronous tools shall reject input
that requires unavailable resources rather than secretly falling through
to cloud inference.

{

"issue_id": "uuid", "category": "spelling",

"range": {"start_utf16": 0, "end_utf16": 4},

"message": "Review this spelling", "source": "dictionary",

"alternatives": \[{"text": "replacement", "rank": 1}\],

"confidence": null, "review_required": true

}

Edit objects contain range, replacement, issue_id and input_revision.
Edits shall be sorted by ascending range with no overlap in an accepted
edit set. The host applies them in reverse offset order transactionally.
The engine shall reject edits outside the selected interval, inside
protected spans or on broken grapheme boundaries.

## 14.3 Transcript and caption schemas

{

"segment_id": "uuid", "revision": 3, "final": true,

"start_ms": 1200, "end_ms": 3500, "text": "Transcript",

"language": "si", "speaker_id": null,

"quality": {"score": null, "flags": \["review_recommended"\]},

"words": \[\], "audio_gap": false

}

Transcript result includes media_asset_id, source_duration_ms, segments
and review_status. Each word, if present, has text, start_ms, end_ms and
nullable score. Segment and word times must be within the asset duration
and start before end. Speaker IDs are nullable and unavailable until
diarization is enabled.

Cue schema: {cue_id, start_ms, end_ms, lines: string\[1..2\],
source_segment_ids: UUID\[\], language, track_id, revision, warnings:
string\[\]}. CaptionProject contains media_asset_id, timebase_num,
timebase_den, revision, source_transcript_revision and tracks. PATCH
requires If-Match equal to the project revision; all changed cues
validate in one transaction. On mismatch return 412 and the current
revision without applying any cue.

## 14.4 Streaming protocol

Client connects and sends start {protocol_version:1, language:"si",
sample_rate:16000, channels:1, encoding:"pcm_s16le", document:null}.
Server replies ready with stream_id, max_buffer_ms and resume_token.
Resume tokens are local, user bound, expire after 30 seconds and are
never logged.

Binary frame layout is an 8 byte unsigned sequence number, an 8 byte
source sample offset, both little endian, followed by signed 16 bit
little endian PCM. The first sequence is zero. Acknowledgements report
highest contiguous accepted sequence and highest durably checkpointed
final segment. Timestamp derives from sample offset, not packet arrival
time. Client finish requests drain and finalization; cancel requests
immediate stop.

Server JSON events use {event_id, stream_id, type, payload}; types are
ack, partial, final, gap, backpressure, error and closed. Partial and
final payloads use the transcript schema. A final segment never changes;
a correction creates a separate user revision. Replayed frames with
already accepted sequence numbers are acknowledged without duplicate
inference.

Job SSE events use monotonically increasing event IDs per job and
Last-Event-ID replay for 10 minutes, with 15 second heartbeats. If
replay expired, the client receives an explicit reset event and fetches
current job state. Stream transcript recovery uses the WebSocket resume
contract, not SSE replay.

## 14.5 Signed package manifest

{

"schema_version": 1, "package_id": "uuid", "version": "1.0.0",

"task": "text", "languages": \["si", "en"\],

"runtime": {"name": "ollama", "version_range": "release-pinned"},

"base_model": "Qwen3-8B", "quantization": "Q4_K_M",

"artifacts": \[{"path": "model.gguf", "sha256": "hex64",

"size_bytes": 5000000000}\],

"license_id": "Apache-2.0", "license_file": "LICENSE.txt",

"rights_approval_id": "approval-id", "evaluation_id": "uuid",

"min_ram_gib": 16, "min_disk_gib": 12,

"training_run_id": null, "publisher_key_id": "key-id"

}

The illustrative size is not a measured model size. Production manifests
shall contain measured values, exact compatible runtime bounds and
tokenizer, template, rules and evaluation artifact hashes. A detached
Ed25519 signature covers the exact UTF-8 manifest bytes; the manifest
hashes every artifact. Import rejects duplicate paths, symlinks,
traversal, executables and undeclared files. Public trust keys are
provisioned with the signed installer or a separately authenticated key
update.

## 14.6 Data model

Use SQLite with WAL for per user metadata, foreign keys enabled and
schema migrations. Store large encrypted binary assets outside the
database. Use a database encryption extension for protected content
fields or encrypted payload blobs with searchable content disabled. No
plaintext transcript or prompt shall enter WAL, temporary files or crash
dumps.

| **Entity**        | **Key relationships and fields**                                  | **Lifetime**                   |
|-------------------|-------------------------------------------------------------------|--------------------------------|
| Profile           | profile_id, OS principal reference, preferences, policy_version   | Until user reset               |
| DocumentRef       | document_id, app_id, opaque host_ref, last_revision               | Session or saved project       |
| Session           | session_id, owner, purpose, consent_ref, retention_mode           | User controlled within policy  |
| Asset             | asset_id, session_id, encrypted_path, MIME, size, digest, key_ref | Session or explicit saved      |
| Job               | job_id, session_id, task, state, input_refs, package_id, retry_of | Content separate from metadata |
| TranscriptSegment | segment_id, job_id, revision, times, encrypted_text, final        | Follows session                |
| CaptionProject    | project_id, media_asset_id, source_revision, revision             | Explicit project retention     |
| CaptionCue        | cue_id, project_id, track_id, times, encrypted_lines              | Follows project                |
| Dictionary        | dictionary_id, owner, language, version, encrypted_entries        | Until user removal             |
| ModelPackage      | package_id, manifest_hash, state, rights_ref, eval_id             | Admin managed                  |
| ModelBinding      | task, profile, active_package_id, previous_package_id             | Persistent and versioned       |
| Consent           | consent_id, purpose, scope, issued_at, revoked_at                 | Minimal proof per policy       |
| Dataset           | dataset_id, immutable manifest_hash, rights_ref, split_hash       | Training zone only             |
| TrainingRun       | run_id, dataset_id, base_digest, config_hash, output_digest       | Training zone only             |
| Evaluation        | evaluation_id, suite_hash, candidate_digest, metrics, decision    | Training and release registry  |
| AuditEvent        | event_id, actor, action, object_ref, result, timestamp            | 90 days default                |

A session owns jobs and assets; a job references exactly one immutable
task model binding snapshot when a model is needed. Caption cues
reference source segments through a join table. Assets can have multiple
derivation links; deletion computes the owned dependency closure and
respects other explicit project references. Models are not private
session content and cannot be cascaded from session deletion.

Index Job(owner,state,created_at), Asset(session_id),
TranscriptSegment(job_id,start_ms),
CaptionCue(project_id,track_id,start_ms), and
ModelPackage(manifest_hash). Use optimistic concurrency for dictionaries
and projects, transactional compare and swap for model bindings and
reference counts, and content checksums for corrupted artifact
detection. Migration failures restore the pre migration metadata backup
and retain encrypted artifacts unchanged.

# 15 Nonfunctional requirements

## 15.1 Certified device profiles

These are proposed certification machines, not universal hardware
guarantees. Phase 0 shall record exact CPUs, GPUs, drivers, RAM, OS
versions and model digests. A device advertises live capability only if
the approved profile passes the sustained test. Installation may support
a lower performance device in batch mode without labeling it real time.

| **Profile**    | **Certification baseline**                                                 | **Intended workload**                                                              |
|----------------|----------------------------------------------------------------------------|------------------------------------------------------------------------------------|
| Minimum CPU    | 4 physical cores, 8 GiB RAM, SSD, 20 GiB free                              | Rules and conversion; Qwen3 4B and small ASR evaluated sequentially; batch mode    |
| Standard CPU   | Intel i7 12700 class, 16 GiB RAM, SSD, 40 GiB free                         | Qwen3 4B or 8B if admitted; small int8 ASR; interactive text, conditional live ASR |
| Apple standard | M2 class, 16 GiB unified RAM, SSD, 40 GiB free                             | Ollama native acceleration; faster-whisper CPU; live ASR conditional on benchmark  |
| Accelerated    | 8 CPU cores, 32 GiB RAM, NVIDIA RTX 3060 12 GiB, SSD, 60 GiB free          | Qwen3 8B quantized, ASR medium or large candidate, scheduled GPU sharing           |
| Training       | Dedicated Linux GPU worker with 24 GiB or larger GPU as experiment permits | LoRA and speech training; memory fit measured per recipe                           |

CTranslate2 prebuilt GPU execution targets NVIDIA CUDA. Do not promise
faster-whisper Metal acceleration on Apple Silicon; its certified
default is CPU. Ollama and ASR have separate hardware support matrices.
CUDA and cuDNN compatibility shall match the pinned faster-whisper and
CTranslate2 release. \[S3, S7\]

## 15.2 Performance and capacity

All latency targets are p95 over at least 100 calls after five warmups,
with input fixtures fixed in the benchmark manifest. Report cold load
separately over ten starts. Background OS noise, power mode and host app
load shall be recorded. CPU or minimum profiles may expose slower batch
behavior; the UI shall never claim the accelerated target on an
unqualified device.

| **ID**  | **Target**                 | **Measurement and acceptance**                                                                                                                          |
|---------|----------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------|
| NFR 001 | Offline correctness        | AT01: all enabled golden workflows pass with outbound DNS and network blocked; zero attempted external connections by engine processes                  |
| NFR 002 | Deterministic text latency | AT23: transliteration and word suggestions ≤100 ms; conversion of 10,000 units ≤300 ms on standard profile                                              |
| NFR 003 | Live ASR latency           | AT10/23: first partial ≤1.5 s after voiced input; final ≤2.5 s after detected endpoint; sustained RTF ≤0.8 on certified live profile                    |
| NFR 004 | Batch ASR throughput       | AT23: 30 minute fixture, RTF ≤1.5 standard CPU and ≤0.5 accelerated profile; all results include source timing                                          |
| NFR 005 | Interactive model latency  | AT23: Qwen3 8B accelerated, 512 input plus 128 output tokens, time to first text ≤3 s; generation ≥10 output tokens/s median; no thinking output        |
| NFR 006 | TTS performance            | AT12/23: approved voice on standard CPU, ≤2 s to first playable sentence and synthesis RTF ≤1.0 for 100 word Sinhala paragraph                          |
| NFR 007 | Idle and memory limits     | AT23: supervisor and idle unloaded workers ≤500 MiB RSS total; peak app plus engine avoids sustained swapping; reserve max of 2 GiB or 20% RAM for host |
| NFR 008 | Responsiveness             | AT23: job submit and cancel acknowledgement ≤250 ms under approved load; cancelled worker releases task resources within 5 s                            |
| NFR 009 | Stability                  | AT03/23: 8 hour mixed load and 2 hour live session; zero unhandled service crashes, no accepted final segment loss, ≤10% memory growth after warmup     |
| NFR 010 | Recovery                   | AT03: service ready within 15 s after restart excluding model load; interrupted batch checkpoint loss ≤30 s of processed audio                          |
| NFR 011 | Data integrity             | AT05/18: no partial exported file is reported successful; exports use temporary file, flush and atomic replace within same filesystem                   |
| NFR 012 | Accessibility              | AT19: all engine controls keyboard accessible, labeled for screen readers, Sinhala composition tested; essential status available without color         |
| NFR 013 | Compatibility              | AT02/17: current and previous supported API minor clients pass contract tests on all certified OS builds                                                |
| NFR 014 | Maintainability            | AT24: one command reproducible build and evaluation from locked inputs; SBOM, schemas, migration and rollback scripts included                          |
| NFR 015 | Observability              | AT07/20: content free health and metrics cover latency, errors, queue depth, worker restarts and model digest; telemetry disabled by default            |

RTF is processing elapsed seconds divided by source audio seconds. First
partial measures from first speech sample delivered to the service;
endpoint delay starts at VAD endpoint time and additionally reports the
VAD silence interval. For TTS, RTF is generation time divided by
generated audio duration. ASR transcript and TTS audio quality shall
pass their gates under the same profile used for latency claims.

Under concurrent Meet and Qwen3 workload, AT23 shall prove live
thresholds remain satisfied or that the scheduler visibly queues text.
Measure a one hour mixed workload with one live stream, one foreground
text request every 30 seconds, and one background caption export. No
admission policy may silently degrade ASR quality or discard frames to
improve latency.

## 15.3 Usability and localization

**NFR 016 • P0.** The engine UI shall support Sinhala and English
labels, locale aware numbers and dates, searchable model names and
actionable error messages. UI language is independent of source text
language. Raw technical stack traces shall not appear to end users.

**NFR 017 • P0.** Every user destructive action shall offer preview or a
reversible result. First time users shall complete offline dictation,
correction and caption export in a moderated task test with at least 80%
task success across ten representative users, including two users
relying on accessibility features. This is a usability gate, not a
statistically representative population claim.

# 16 Model and data training pipelines

## 16.1 Pipeline requirements

**FR TRNPIPE 001 • P0 • Provenance and rights.** Every training item
shall reference provenance, language, permitted use, consent where
required and a dataset version. Items without approved rights shall be
quarantined. Private app content is excluded unless separately and
explicitly contributed. Acceptance: AT24 and AT20.

**FR TRNPIPE 002 • P0 • Reproducibility.** A run shall record base model
digest, dataset manifests, split hashes, code commit, container digest,
dependencies, seed, hyperparameters, hardware, duration and output
checksums. Resume shall validate input hashes before loading a
checkpoint. Acceptance: AT24.

**FR TRNPIPE 003 • P0 • Leakage control.** Train, development and locked
test partitions shall be separated by speaker, document source,
collection session and near duplicate cluster as applicable. Test
references shall not be used for prompt tuning, threshold selection or
synthetic training generation. Acceptance: AT24.

**FR TRNPIPE 004 • P1 • Task recipes.** Separate pipelines shall support
text adaptation, ASR fine tuning, TTS training and deterministic
resource builds. Each pipeline shall emit a deployable candidate and
comparable evaluation report. Acceptance: AT24 and AT25.

**FR TRNPIPE 005 • P0 • Promotion.** A candidate shall pass quality,
subgroup, privacy, runtime, memory, license and regression gates before
signing and activation. Rejected candidates shall retain diagnostic
reports and never replace the approved baseline. Acceptance: AT21, AT24
and AT25.

**FR TRNPIPE 006 • P1 • Feedback.** Correction feedback shall remain
local by default. Contribution shall require preview, scope limited
consent, redaction and an explicit export action. Withdrawal shall
remove source records from future datasets and record the effect on
already trained models without claiming automatic unlearning.
Acceptance: AT20 and AT24.

## 16.2 Collection and preparation

The data steward shall maintain a source register for Sinhala documents,
parallel translations, speech recordings, pronunciation examples,
spelling errors and transliteration pairs. Collect formal and
conversational language, regional variation, code switching, quiet and
noisy audio, and domain terms from Office, meetings and media. Speaker
demographic data is optional, minimized and consented; do not infer
sensitive attributes from voice.

Audio preparation shall validate sample rate, clipping, channel quality,
transcript alignment and consent. ASR training uses the rate required by
the selected base model, normally 16 kHz; TTS preserves high quality
original recordings at the voice recipe’s native rate before controlled
resampling. Remove invalid or mislabeled segments and document excluded
categories. Keep silence and hard negative examples for hallucination
evaluation.

Text preparation shall preserve Sinhala Unicode and joiners, version
normalization, detect personal information and deduplicate both exact
and near matches. Protected placeholders shall be consistent across
parallel sentence pairs. Two Sinhala reviewers shall adjudicate a
sampled subset; annotation guidelines shall define colloquial spellings,
punctuation, names, borrowed terms and whether an edit changes meaning.

Suggested pilot planning volumes are 50–100 hours of licensed diverse
ASR speech, 10–20 hours of a single consenting TTS speaker, 10,000
reviewed text instruction examples and 10,000 parallel translation
segments. These are collection planning assumptions, not sufficiency
claims. A learning curve and held out quality results determine whether
more data is required. Training from a limited pilot cannot waive a
failed release gate.

## 16.3 Text model adaptation

Start with prompting, structured outputs, curated lexicons and domain
glossaries. If evaluation fails, fine tune the pinned Qwen3 base using
LoRA or QLoRA in a separate Transformers compatible training
environment. A pilot sweep may compare learning rates 1e-5 and 5e-5, one
to three epochs, rank 16 or 32 and context 2,048 or 4,096, constrained
by memory. Freeze the selected recipe using development data only.

Train task specific examples for minimal edits, formal and colloquial
registers, translation, protected span preservation and valid JSON.
Compare against the unmodified base and deterministic baseline. Merge
adapters only with the exact originating base when required, convert to
a format supported by the pinned Ollama runtime, quantize with the
compatible toolchain and import locally. Do not assume arbitrary
adapters or every architecture are directly importable. Validate
quantized output quality and tokenizer and template equivalence
separately. Ollama is used for deployment, not gradient training. \[S1,
S8\]

## 16.4 ASR adaptation

Fine tune a multilingual Whisper compatible checkpoint with approved
Sinhala transcripts and code switched examples. Candidate recipe
exploration shall log frozen layers, learning rate, batch accumulation,
audio augmentation and decoding settings. Select on normalized WER and
critical name and number error rates, not loss alone. Avoid an English
only distilled checkpoint for Sinhala.

Export the resulting checkpoint to CTranslate2 with matching tokenizer
and feature configuration. Test float and int8 candidates with the
actual faster-whisper worker, VAD and window reconciliation path. Check
empty audio, repeated phrases, music, long silence, overlapping speech
and names not present in training. The runtime artifact is approved only
after conversion parity and end to end scoring pass. \[S3\]

## 16.5 Sinhala voice training

Record or acquire speech with rights that explicitly cover synthetic
voice creation, distribution and intended commercial use. Use a
controlled recording setup and a phonetically varied script covering
Sinhala consonants, vowels, conjuncts, numerals, dates, abbreviations
and borrowed words. A voice contract shall define revocation handling
and allowed uses.

Build a versioned text normalization and grapheme or phoneme front end,
pronunciation lexicon and native rate acoustic dataset. Train a VITS
based baseline and compare held out intelligibility and native listener
ratings. Export to the chosen local runtime, optionally ONNX if the
model exports correctly, and test parity, rate controls and long
sentence stability. A model that sounds natural but misreads numbers
fails. Commercial distribution remains disabled until the rights
approval and runtime package are complete.

## 16.6 Deterministic resource pipelines

Transliteration rules, dictionaries and legacy mappings shall be
versioned source assets with review histories and golden fixtures.
Compile them reproducibly, verify grapheme boundaries and mappings, and
sign their output bundles. Every supported legacy profile shall
enumerate reversible and irreversible subsets. No model training is
required for exact encoding conversion.

## 16.7 Artifact retention

Store training runs and evaluation reports in the controlled training
registry with access scoped to role. Checkpoints shall be encrypted and
retained according to source licenses and organization policy. Keep a
production package’s full lineage while it remains supported. Raw
collection data shall have explicit expiry, deletion and withdrawal
processes; production devices shall not receive training datasets or
optimizer states.

# 17 Evaluation and model release gates

## 17.1 Benchmark design

The language lead shall approve a versioned locked benchmark before
training comparison. Suggested initial minimums are ten hours of ASR
audio from at least 50 unseen speakers; 1,000 translation segments per
direction; 2,000 spelling and grammar examples including correct
negative examples; 1,000 Singlish pairs with multiple accepted
spellings; 5,000 legacy mapping fixtures per profile; and 200 TTS
prompts with at least 20 native listeners in balanced listening
assignments. Small samples shall be labeled and shall not support broad
quality claims.

Each suite shall include regional speech where consented, formal and
informal register, noisy environments, code switching, numbers, names,
abbreviations, long content, secure input exclusions and malformed
Unicode. Track counts and metrics per meaningful subgroup; never fill
demographic gaps by inferring identity from voice. Audio and document
source groups shall be disjoint from training.

## 17.2 Quality targets

These are proposed initial release thresholds, not measured claims about
Qwen3, Whisper or an available Sinhala voice. Phase 0 shall establish
baseline scores and confirm feasibility. A failing feature remains
disabled or experimental with explicit labeling until a reviewed
requirement change or a passing candidate exists.

| **Task**           | **Release gate**                                                          | **Measurement**                                                     |
|--------------------|---------------------------------------------------------------------------|---------------------------------------------------------------------|
| ASR clean Sinhala  | Normalized WER ≤20%; CER ≤10%                                             | Fixed tokenization and normalization, raw scores also reported      |
| ASR noisy or mixed | WER ≤35%; number and name token accuracy ≥90% on critical subset          | Report per noise and code switching stratum                         |
| ASR silence        | No sentence output on ≥99% of 500 silence and non speech clips            | Investigate all fabricated speech outputs                           |
| TTS                | MOS ≥3.8/5 with 95% CI lower bound ≥3.5; intelligibility ≥95%             | Blind native listener tests; numeric reading accuracy ≥98%          |
| Caption timing     | Median cue boundary error ≤300 ms; p95 ≤750 ms against approved alignment | Separate speech start and end error; no out of duration cues        |
| Transliteration    | Top 1 ≥90%, top 3 ≥97% accepted candidate match                           | Multiple acceptable references, mixed input preserved               |
| Legacy conversion  | 100% exact match on declared supported reversible fixtures                | Unmappable cases always reported; zero silent loss                  |
| Word suggestions   | Intended candidate recall at 5 ≥85% on held out prefixes                  | Personalization off for baseline                                    |
| Spelling           | Precision ≥95%, recall ≥80%, correct sentence false alarm ≤5%             | Annotated error spans and corrected negatives                       |
| Grammar            | Precision ≥90%, recall ≥70%, meaning preservation ≥98%                    | Native reviewer adjudication; edits on correct negatives ≤5%        |
| Translation        | ≥90% segments rated at least 4/5 adequacy; ≥85% fluency at least 4/5      | Two bilingual reviewers and adjudication; each direction separately |
| Text integrity     | 100% protected placeholders and critical numeric fields preserved         | Changes explicitly requested by user evaluated separately           |
| Structured output  | ≥99% valid first attempt; 100% invalid outputs blocked                    | 1,000 varied inputs; report bounded repair rate                     |

Report chrF and other automatic translation scores as supporting trend
metrics with exact implementation and reference set; do not substitute
them for bilingual review. Define WER as (substitutions + deletions +
insertions) / reference words; CER uses reference code points under the
fixed normalization profile. Also report grapheme error rate to expose
Sinhala combining sequence errors. No text cleanup may hide numerical
errors.

## 17.3 Regression and subgroup criteria

A candidate shall meet the absolute gate and shall not worsen WER or CER
by more than 2 percentage points, or precision, recall or human pass
rates by more than 2 points, relative to the active baseline on the same
suite. A noise, region or code switch stratum with at least 100
evaluated utterances shall not regress WER by more than 5 points.
Underrepresented strata shall be reported as insufficient evidence
rather than assumed passing.

Use paired bootstrap confidence intervals for ASR and translation
comparisons, clustering by speaker or source document to avoid treating
correlated segments as independent. Voice ratings shall report listener
count, prompt count and uncertainty. A language reviewer shall review
critical failures even if aggregate scores pass. Severe corruption of
dates, amounts, names or negation blocks release until corrected or
explicitly removed from supported scope.

## 17.4 Evaluation report schema

Each report shall contain evaluation_id, candidate_digest,
baseline_digest, suite_digest, dataset and license references, hardware
manifest, runtime lock, decoding configuration, per task and subgroup
scores, confidence intervals, performance metrics, failure examples in
the protected review system, reviewer identities, decision and date. The
production package includes a sanitized report without private examples.

A release owner and language lead shall approve the report; the security
owner approves privacy and package integrity evidence. Retraining,
quantization, template changes, VAD changes, normalization changes and
dictionary changes all require the affected evaluation suites to rerun.

# 18 Privacy and security requirements

**SEC 001 • P0 • Local execution boundary.** Engine and worker inference
shall use only local approved assets and loopback or user restricted
IPC. Set Ollama local only mode using the supported configuration, such
as OLLAMA_NO_CLOUD=1, and verify it at startup. Deny external
connections at the engine process boundary in offline mode; cloud
prohibition alone is insufficient to prevent updater or package download
traffic. Acceptance: AT01 and AT20. \[S9\]

**SEC 002 • P0 • Least privilege.** Credentials shall carry app and
operation scopes. Model administration, policy updates and dataset
contributions require separate scopes and native user mediated
authorization. The per user engine shall run without administrator
rights. Workers shall not inherit unnecessary network, filesystem or
credential access. Acceptance: AT20 and AT21.

**SEC 003 • P0 • Encryption.** Private persisted assets shall use
authenticated encryption such as AES-256-GCM with random nonrepeating
nonces. Per session data keys shall be wrapped by a master key held in
the OS vault. Encrypted metadata or encrypted payloads shall prevent
plaintext leakage through SQLite and temporary storage. Logs and crash
reports shall exclude content. This protects stored data, not a
compromised signed in OS session. Acceptance: AT20.

**SEC 004 • P0 • Recording and contribution consent.** Microphone and
system audio capture shall require explicit user start and platform
permission. Meet shall provide a visible capture indicator and
participant notice flow. Training contribution is a separate purpose and
shall never be inferred from recording consent or spelling corrections.
Acceptance: AT19 and AT20.

**SEC 005 • P0 • Retention.** Raw live audio shall remain in bounded
memory by default and be discarded after finalization; optional
recording requires an explicit save action. Session only transcripts and
outputs expire on session close, with crash cleanup no later than 24
hours after the last heartbeat. Explicitly saved projects remain until
user deletion within policy. Job metadata retains 30 days and content
free audit events 90 days by default. Acceptance: AT20.

**SEC 006 • P0 • Deletion.** User deletion shall stop processing, remove
indexes, clear caches and destroy session artifact keys within 60
seconds. Do not promise physical secure erase on SSDs. Backups shall use
the same retention boundary; deleting wrapped session keys must render
backed up payload copies unreadable. User exported files and externally
copied keys are separate copies and shall be disclosed. Acceptance:
AT20.

**SEC 007 • P0 • Package and parser safety.** Importers shall reject
unsigned packages, invalid hashes, archive traversal, symlink escape,
excess expansion, incompatible runtimes and disallowed licenses. Media
decoders shall run with resource limits and no network access. Document
macros and arbitrary model supplied scripts shall never execute.
Acceptance: AT21.

**SEC 008 • P0 • Untrusted model output.** Prompts embedded in documents
shall not change engine policy or trigger file access. The engine shall
validate output schemas and edit ranges and escape exported markup.
Generated suggestions shall have no authority to modify permissions or
install models. Acceptance: AT20 and AT25.

**SEC 009 • P1 • Optional diagnostics.** Any outbound diagnostics
feature shall be off by default, organization controllable and limited
to previewed content free fields. It shall never send audio, text, input
hashes, file paths or persistent document IDs. Offline mode shall
prohibit diagnostics egress even if previously enabled. Acceptance: AT07
and AT20.

## 18.1 Threat model and mitigations

| **Threat**                              | **Boundary at risk**        | **Required control**                                                                  |
|-----------------------------------------|-----------------------------|---------------------------------------------------------------------------------------|
| Malicious local webpage calls localhost | Gateway authentication      | Exact Origin and Host checks, CSRF protection, no wildcard CORS, scoped credential    |
| Hostile document prompts                | Text worker and host editor | Content isolation, no tools, schema and range validation, user preview                |
| Tampered or malicious model archive     | Registry and worker         | Trusted signature, hashes, safe extraction, data only packages                        |
| Decoder exploit or media bomb           | ASR and export workers      | Isolated process, CPU and memory limits, duration and size caps, patched dependencies |
| Other local OS user reads transcripts   | Storage and IPC             | OS ACLs, per user service, encrypted payloads, vault keys                             |
| Lost device or leaked backup            | Persisted data              | Authenticated encryption, key separation and deletion policy                          |
| Same user malware or admin compromise   | Active process memory       | Document limitation; endpoint hardening and least privilege reduce exposure           |
| Sensitive sample enters training        | Contribution boundary       | Separate opt in, preview, redaction, rights review and lineage                        |

## 18.2 Governance and audit

The release shall include a data inventory, retention schedule, voice
and dataset rights records, SBOM, threat model and security review.
Legal and privacy owners shall determine jurisdiction specific
participant recording and data protection obligations before deployment;
this SRS defines engineering controls rather than asserting legal
compliance. No online license heartbeat may be introduced into the
offline runtime. Revocation and licensing checks use imported signed
local records.

# 19 Deployment and operations

## 19.1 Packaging and installation

Ship signed OS installers containing the supervisor, gateway, SDK
bridge, deterministic language resources, database migrations and
runtime locks. Supply models as optional signed offline packs to manage
download size. Each pack shall include weights, tokenizer, templates,
voice or mapping resources, license files, evaluation summary and
manifest. Package all required decoding libraries locally and test
installation on a clean machine without development tools.

Use native Ollama on macOS for supported local acceleration. Do not
promise GPU passthrough from Docker Desktop on macOS. Linux training and
CI may use locked containers. Windows packages shall identify CPU and
CUDA variants explicitly and validate required drivers before enabling
acceleration. Avoid silently installing system wide GPU drivers. \[S9\]

Baseline service configuration:

mode: offline

transport: per_user_ipc

http_bridge: optional_loopback

ollama:

host: 127.0.0.1:11434

cloud_disabled: true

allowed_model_digests: signed_policy

scheduler:

live_streams: 1

gpu_jobs: 1

max_queued_per_principal: 8

storage:

content_encryption: required

raw_audio_default: memory_only

session_expiry_after_crash_hours: 24

telemetry:

enabled: false

cloud_disabled is a WorkSuite configuration field translated by the
supervisor to the pinned Ollama setting; it is not asserted to be a
native Ollama YAML key. Bindings, exact package paths, token secrets and
encryption keys shall not be hard coded into this example or checked
into source control.

## 19.2 Startup and readiness

Startup shall validate policy, initialize credential storage, verify
schema compatibility, recover interrupted jobs, check disk and memory,
load registry bindings and start workers lazily. Liveness means the
gateway responds. Readiness is per capability and requires a compatible
approved model and successful smoke test. One broken voice package shall
not block font conversion or spelling.

If an existing Ollama service is present, WorkSuite shall verify
ownership, local only settings and version compatibility or use a
separately managed instance on a distinct local port. It shall not
reconfigure another application’s service without an explicit admin
installation action. The managed instance shall permit only approved
local model references and shall not accept arbitrary remote model IDs
from clients.

## 19.3 Updates and rollback

Updates are explicit user or admin actions through a connected
maintenance session or offline bundle import. Verify installer and
bundle signatures before staging. Retain the last approved engine and
model binding. Use expand and contract database migrations so the
previous supported engine can read the rollback schema, or restore the
pre upgrade encrypted backup before rollback. Never pair an old engine
with an incompatible new schema.

An activation transaction stages files, validates hashes and
permissions, runs readiness and a task smoke test, updates the binding
and records an audit event. Failure before commit preserves the current
binding; failure after commit triggers automatic health rollback when
the new package cannot complete smoke jobs. Quality regressions require
manual review and rollback because runtime health alone cannot detect
them.

## 19.4 Backup and recovery

Backup saved projects, preferences, dictionaries and minimal metadata
through an explicit encrypted export. Backups shall include wrapped per
session keys only if the user authorizes portable recovery with a
separate recovery secret. Clearly disclose that a portable export is an
independent copy whose keys cannot be remotely destroyed. Normal local
backup follows the deletion key boundary in SEC 006.

For process crashes, final ASR segments and completed jobs shall survive
according to checkpoint policy. For disk loss, recovery is limited to
the most recent user exported backup; no cloud restore is implied. Run a
restore drill per release and verify dictionary versions, caption
timings, model references and decryptability. Models may be reimported
from signed offline packs instead of duplicated in backups.

## 19.5 Operations and ownership

Support shall use health status and sanitized diagnostic bundles. AI
engineering owns runtime compatibility; the language lead owns Sinhala
quality; security owns trust keys and vulnerability response; product
owns supported capability labels. A corrupted package shall be
quarantined, active work halted for that package and a compatible
retained version offered. Never publish private examples in support
bundles.

The installer shall check free space against pack expanded bytes,
staging overhead and rollback retention. Require at least twice the
incoming pack’s expanded bytes plus the declared runtime scratch
reserve. Disk full shall return a visible error before mutating a
document or replacing a successful export.

# 20 Verification and test strategy

## 20.1 Test levels

Unit tests cover normalization, grapheme mapping, transliteration,
encoding tables, cue constraints, policy logic, signatures, state
transitions and schema validation. Property tests and fuzzing cover
malformed Unicode, offset maps, archive traversal, oversized media and
streaming sequence recovery. Deterministic components use exact golden
fixtures; generative components use bounded structural checks and locked
quality suites.

Integration tests run the real pinned Ollama, faster-whisper and TTS
workers against offline local assets, including cancelled jobs and model
replacement. Adapter tests run against host contract mocks followed by
actual WorkSuite builds. End to end tests cover the six workflows with
outbound traffic blocked. Fault injection includes worker kill, full
disk, corrupt model, expired credential, microphone removal, OS sleep,
service restart and interrupted import.

Performance tests use the certified machines and workload defined in
section 15. Security tests include local webpage attacks, incorrect
origins, privilege escalation, cross user file access, prompt injection,
malicious metadata and package tampering. Native Sinhala reviewers run
quality and font shaping checks. Accessibility users validate keyboard
composition, screen reader announcements and secure field behavior.

## 20.2 Acceptance test catalog

| **Test** | **Procedure and pass condition**                                                                     | **Requirement coverage**                       |
|----------|------------------------------------------------------------------------------------------------------|------------------------------------------------|
| AT01     | Clean offline install; run all installed golden workflows; capture zero engine egress attempts       | CORE 001; NFR 001; SEC 001                     |
| AT02     | Remove one model; negotiate clients and capabilities; only affected feature unavailable              | CORE 002; INT 006; NFR 013                     |
| AT03     | Submit, cancel, restart and retry jobs; verify legal transitions, idempotency and checkpoints        | CORE 003/008; NFR 009/010                      |
| AT04     | Inspect 100 results for complete pinned provenance; compare deterministic outputs across OS          | CORE 004                                       |
| AT05     | Change document during inference; stale edits rejected; accepted edits undo atomically               | CORE 005; WR 005; INT 002; NFR 011             |
| AT06     | Switch OS users; dictionaries and tokens inaccessible; opt in ranking reset works                    | CORE 006; TRN 003                              |
| AT07     | Export diagnostics and inspect bytes; no audio, text, paths or sensitive identifiers                 | CORE 007; NFR 015; SEC 009                     |
| AT08     | Import each supported format plus malformed files; validate timing and clean failure                 | ASR 001/005                                    |
| AT09     | Run locked clean, noisy, mixed and silence suites; meet all ASR gates                                | ASR 002/004/007                                |
| AT10     | Run live session; disconnect, replay, skip frames and remove mic; no duplicate finals or silent gaps | ASR 003/006; INT 003; NFR 003                  |
| AT11     | Edit and export caption fixtures; timing and grapheme checks pass; SRT/VTT round trip                | CAP 001–004; ASR 005                           |
| AT12     | Blind TTS scoring, number reading, playback, cancellation and export; meet voice gates               | TTS 001–004; NFR 006                           |
| AT13     | Held out transliteration pairs and mixed text; meet recall gates and preserve literal spans          | TRN 001–003                                    |
| AT14     | Exact mapping fixtures, joiners and ambiguous spans; zero silent loss and strict rejection           | FNT 001–003                                    |
| AT15     | Both translation directions and caption tracks; human gates and placeholder integrity pass           | MT 001–004 when enabled; CAP 005               |
| AT16     | Negative and error writing sets; meet precision/recall; safe ranges and stale discard                | WR 001–006                                     |
| AT17     | Actual Desktop, Office and Meet adapter workflows pass revision and permission contracts             | INT 001–003; FNT 004; NFR 013                  |
| AT18     | FrameFlow caption/narration export, full disk and retry; preserve timeline and media                 | INT 004; CAP 006; CORE 008; NFR 011            |
| AT19     | Keyboard and screen reader tasks; secure field and unknown field capture blocked                     | INT 005; SEC 004; NFR 012/016/017              |
| AT20     | Cross user access, egress, injection, encryption inspection, retention expiry and timed deletion     | SEC 001–006/008/009; ADM 005; TRNPIPE 001/006  |
| AT21     | Unsigned, altered, revoked, oversized and unauthorized packages rejected; licenses visible           | ADM 001/002/005/007; TTS 001/005; SEC 002/007  |
| AT22     | Activate with concurrent jobs; new jobs pin new package; rollback and removal safe                   | ADM 003/004                                    |
| AT23     | Certified hardware mixed load, latency, RTF, memory and 8 hour soak pass                             | ADM 006; NFR 002–010                           |
| AT24     | Rebuild from immutable manifests, inspect consent and splits, restore checkpoint and compare         | TRNPIPE 001–006; NFR 014                       |
| AT25     | Compare base and candidate including quantized artifacts; blocked release for failed gate            | TRNPIPE 004/005; SEC 008; all section 17 gates |
| AT26     | Optional diarization benchmark: DER ≤20% nonoverlap; unknown and overlap review works                | ASR 008 when enabled                           |

NFR 016 localization also requires a Sinhala language reviewer to
inspect all user facing engine strings. NFR 017 usability requires the
moderated study described in section 15. AT17 shall be rerun when each
actual host build becomes available; mocks alone do not qualify
production integration.

## 20.3 Test data and reporting

Tests shall never use production recordings without explicit test use
consent. Maintain synthetic adversarial cases and licensed realistic
fixtures. Store protected evaluation outputs in the review environment,
publish aggregate sanitized results and attach failure identifiers to
defects. Record test ID, requirement IDs, build and model digests,
fixture hash, hardware, observed result, status and reviewer.

An API release requires schema validation, SDK contract tests,
authorization negatives and backwards compatibility evidence. A model
release requires the locked language and resource suites even if
application code is unchanged. A package or installer release requires
signature and offline installation tests on every certified OS.

# 21 Phased delivery plan

## Phase 0 Feasibility and contracts

Indicative duration: three weeks with a staffed cross functional team.
Freeze WorkSuite adapter contracts, certified hardware, Sinhala
annotation guidelines, data rights inventory and benchmark manifests.
Benchmark Qwen3 4B and 8B, multilingual Whisper candidates and the
Sinhala voice path. Prototype grapheme offsets, strict converter rules
and offline packaging.

Exit gate: reproducible baseline reports; approved scope for initial
languages and legacy profiles; measured memory fit; chosen commercial
TTS path; threat model; adapter owners; API examples validated by client
teams. If Sinhala quality or voice rights fail, retain the engine
architecture but disable the affected feature pending remediation. No
performance claim is approved merely by choosing a model.

## Phase 1 Offline core and preview release

Indicative duration: six weeks after phase 0. Deliver CORE 001–007; ASR
001–006; TTS 001–003 and 005 if rights and quality pass; CAP 001–004;
TRN 001–002; FNT 001–003; WR 001, 003 and 005; ADM 001–005; INT 001–002
and 006; all applicable P0 pipeline and security controls. Include a
Desktop caption project interface before the full FrameFlow adapter.

Exit gate: AT01–14, applicable AT16–17 and AT19–25 pass for supported
features; no unresolved P0 defect; offline installer tested; rollback
drill complete. Unsupported features remain hidden or explicitly
unavailable, never presented as production ready. Office spelling and
dictation shall meet their gates on the designated supported profile.

## Phase 2 Complete WorkSuite release

Indicative duration: six to eight weeks after phase 1. Deliver all P1
requirements, including grammar, phrase suggestions, Sinhala–English
translation, rich text conversion, Meet and FrameFlow integration, OS
input, translated captions, burn in and full training recipes. Reuse the
core privacy controls and quality gates for each added feature.

Exit gate: all P0 and P1 tests pass on certified OS builds with real
host adapters; bilingual review passes both directions; TTS commercial
voice package approved; complete model management UI and rollback
exercise. The product’s general availability milestone requires this
phase; a phase 1 preview is not the full requested suite.

## Phase 3 Expanded languages and advanced speech

Indicative duration: planned after evidence from phase 2. Add approved
diarization, Sinhala–Tamil translation and additional voices or legacy
profiles. Introduce expanded fleet tooling if needed. Each addition has
its own rights, quality, subgroup and device support report; no
inherited pass from Sinhala–English or single speaker ASR.

Exit gate: AT15 extended per new language direction, AT26 for
diarization and applicable regression suites pass. Quality and privacy
thresholds remain enforceable when hardware profiles change.

## 21.1 Staffing and dependencies

Planning assumes one technical lead, two engine engineers, one desktop
and SDK engineer, one shared integration engineer, one ML engineer, one
QA engineer and a part time security specialist, with a Sinhala language
lead and contracted reviewers. Training data acquisition and host app
work may require additional staffing. Durations are estimates, not
delivery commitments.

Critical path: dataset and voice rights → locked benchmarks → model
feasibility → runtime packages → adapters → quality and offline
certification. Platform installers, host API availability, commercial
TTS rights and data collection can each delay delivery independently of
model code. Maintain a dependency board with owner, due date, status and
affected requirement IDs.

## 21.2 Definition of done

A requirement is done when its implementation, user facing behavior, API
documentation, negative cases, applicable privacy controls and
acceptance evidence are complete. A phase is done when all its enabled
capabilities pass their absolute and regression gates on certified
profiles. Product, engineering, QA, language and security owners sign
the release record. Exceptions require a documented scope reduction or
changed requirement version; they cannot silently waive P0 protections.

# 22 Risks and open decisions

| **ID** | **Risk and impact**                                      | **Mitigation and accountable role**                                                                        |
|--------|----------------------------------------------------------|------------------------------------------------------------------------------------------------------------|
| R01    | Qwen3 Sinhala quality below useful level                 | Benchmark before commitment; deterministic first path, curated data and LoRA; ML lead                      |
| R02    | Whisper misses colloquial speech or hallucinates silence | Diverse test audio, VAD and review, fine tuning and explicit uncertainty; speech lead                      |
| R03    | Sinhala TTS voice or training rights unavailable         | Secure recordings and usage rights early; keep feature unavailable until cleared; product and rights owner |
| R04    | Low RAM devices cannot run ASR and Qwen3 together        | Admission control, small profiles and queueing; runtime lead                                               |
| R05    | Apple live ASR fails latency without CUDA                | CPU benchmark, batch support label; alternative backend only through explicit scope change; platform lead  |
| R06    | Legacy mappings are ambiguous or variant dependent       | Exact profile IDs, golden fixtures, strict mode and original preservation; language lead                   |
| R07    | WorkSuite host APIs lack revision or undo support        | Phase 0 adapter validation; preview and copy until transactional support exists; integrations lead         |
| R08    | Caption layout damages Sinhala shaping                   | Grapheme aware breaks and real font rendering tests; media lead                                            |
| R09    | Private content leaks into logs or training              | Content free logging, separate consent, encryption and egress tests; security lead                         |
| R10    | Quantization or conversion changes quality               | Test deployed artifact against base and approved thresholds; ML lead                                       |
| R11    | Bad package update makes tools unusable                  | Signed staged import, task smoke tests and atomic rollback; release lead                                   |
| R12    | Benchmarks overfit formal Sinhala or common speakers     | Source separated splits, subgroup reports and rotating future holdouts; language lead                      |
| R13    | OS permissions and Office host versions vary             | Capability negotiation and certified build matrix; integrations lead                                       |
| R14    | Offline revocation list becomes old                      | Show freshness and import signed policy updates; organization admin                                        |
| R15    | Suggested timelines underestimate data collection        | Track rights and annotation as explicit dependencies; product lead                                         |

## 22.1 Decisions required before implementation freeze

D01: confirm exact WorkSuite Desktop and Office extension APIs and
target OS builds. Owner: integrations lead. Deadline: phase 0 exit.
Default: adapters implement the contracts in section 11 and unsupported
direct editing stays disabled.

D02: choose commercial Sinhala voice and training corpus. Owner: product
and language leads. Deadline: before TTS feature activation. Default: no
bundled research-only voice in the commercial build.

D03: validate FM Abhaya and DL Manike variant mappings and font
distribution rights. Owner: language lead. Deadline: phase 0 exit.
Default: ship only the profiles with complete reversible fixture
evidence; font binaries require separate permission.

D04: approve numerical quality thresholds and benchmark coverage. Owner:
product, QA and language leads. Deadline: phase 0 exit. Default:
thresholds in section 17 remain blocking targets until a versioned
change is approved.

D05: finalize initial model digests, runtime versions and device
profiles from measurement. Owner: AI engine lead. Deadline: phase 0
exit. Default: Qwen3 4B and 8B and multilingual Whisper candidate
evaluation; no floating model tags in production.

D06: finalize organization retention overrides and participant notice
policy for target deployments. Owner: privacy and security leads.
Deadline: before Meet pilot. Default: session only content, explicit
capture and no training contribution.

# 23 Release acceptance criteria

## 23.1 Product acceptance

AC01: With a clean provisioned device disconnected from external
networks, all enabled tools complete the offline golden workflows, and
monitored engine processes attempt zero egress. Evidence: AT01 and SEC
001 review.

AC02: Dictation handles Sinhala, code switching, silence and stream
reconnection within the approved language and device thresholds. Final
segments are not duplicated, dropped silently or inserted into stale
documents. Evidence: AT05 and AT08–10.

AC03: Sinhala read aloud and narration use a voice with approved
distribution and speaker rights, meet intelligibility and number reading
targets, and stop promptly on cancel. Evidence: AT12 and AT21.

AC04: Captions are editable, shaped correctly, time valid, readable
within the profile constraints or visibly flagged, and exported without
corrupting source media. Evidence: AT11, AT15 and AT18.

AC05: Singlish, Unicode conversion and writing suggestions preserve
protected text and Sinhala grapheme boundaries. Every unsupported legacy
sequence is reported. Accepted edits undo in one transaction. Evidence:
AT05 and AT13–16.

AC06: Translation and grammar meet human judged quality and integrity
gates for every enabled direction and register; invalid or unsafe
structured output is blocked. Evidence: AT15, AT16 and AT25.

AC07: All five WorkSuite surfaces obey capability, consent, revision and
ownership contracts, including secure input exclusions. Evidence:
AT17–19 with actual host builds, not mocks alone.

AC08: An administrator can import, verify, activate, roll back and
remove packages safely without breaking active jobs or bypassing rights
checks. Evidence: AT21–22 and offline deployment drill.

AC09: Training runs and model releases are reproducible from approved
datasets, with no split leakage, full lineage, quantized artifact
evaluation and explicit promotion decisions. Evidence: AT24–25.

AC10: Encryption, authorization, deletion, retention, package integrity
and prompt injection controls pass, and privacy content is absent from
diagnostics. Evidence: AT07 and AT19–21.

AC11: Certified devices satisfy the advertised performance envelope,
stable mixed workload, recovery and resource admission behavior.
Evidence: AT03 and AT23.

AC12: Installation, signed updates, schema migration, encrypted backup
restore and engine rollback pass on every certified platform;
documentation, SBOM, OpenAPI and administrator instructions accompany
the build. Evidence: deployment certification record and AT01, AT22 and
AT24.

## 23.2 Release evidence checklist

The release record shall link the signed installer and model manifests;
source commit and dependency locks; requirement to test report;
benchmark suite digests and scores; native language reviewer approvals;
package and voice rights records; security review; performance and soak
reports; adapter compatibility matrix; retention and deletion evidence;
migration and restore drill; and known limitations visible in the
product.

A preview release may include a smaller declared capability set at
phase 1. General availability requires all P0 and P1 requirements and
AC01–12. P2 capabilities require the same shared controls plus their
task specific gates when enabled. No numerical score in this document is
a claim that a candidate model has already achieved it.

# 24 References and glossary

## 24.1 Technical sources

Technical dependency statements were checked against primary sources on
24 October 2026. Sources support component capabilities and constraints;
product thresholds, architecture choices, schedules and example schemas
are WorkSuite requirements. Release engineering shall snapshot exact
upstream versions and license texts rather than relying on live URLs
alone.

\[S1\] Qwen team. Qwen3 8B model card. Model identity, text inference
and Apache 2.0 declaration. https://huggingface.co/Qwen/Qwen3-8B

\[S2\] Ollama. Generate a chat message. Native /api/chat, structured
format, streaming, thinking capability and keep alive settings.
https://docs.ollama.com/api/chat

\[S3\] SYSTRAN. faster-whisper official README. CTranslate2 execution,
local model loading, VAD, timing, conversion and CUDA dependencies.
https://github.com/SYSTRAN/faster-whisper

\[S4\] Meta. MMS TTS model collection. Multilingual TTS research
comparison and CC BY NC 4.0 declaration; individual artifact and voice
rights still require verification.
https://huggingface.co/facebook/mms-tts

\[S5\] Unicode Consortium. Unicode Standard Annex 29 Text Segmentation.
Grapheme and text boundary behavior; implementations shall pin the
supported revision. https://unicode.org/reports/tr29/

\[S6\] Unicode Consortium. Unicode 17 chapter 13 section 13.2 Sinhala.
Encoding, vowel letters and shaping context.
https://unicode.org/versions/Unicode17.0.0/core-spec/chapter-13/

\[S7\] OpenNMT. CTranslate2 hardware support. Prebuilt CPU and NVIDIA
GPU support. https://opennmt.net/CTranslate2/hardware_support.html

\[S8\] Ollama. Importing a model. Supported import procedures and
prequantized GGUF handling; architecture compatibility must be verified
for the pinned runtime. https://docs.ollama.com/import

\[S9\] Ollama. FAQ. Local only configuration, loopback binding, model
memory considerations and macOS Docker GPU limitation.
https://docs.ollama.com/faq

## 24.2 Glossary

| **Term**         | **Definition**                                                                                |
|------------------|-----------------------------------------------------------------------------------------------|
| ASR              | Automatic speech recognition; audio to text                                                   |
| TTS              | Text to speech; text to generated audio                                                       |
| Singlish         | Latin letter input used to represent Sinhala; multiple informal spellings exist               |
| Unicode Sinhala  | Standard encoded Sinhala characters rather than font specific glyph codes                     |
| Legacy encoding  | A mapping from character positions to glyphs used by a specific older font workflow           |
| Grapheme cluster | A user perceived character boundary used for safe editing and line breaks                     |
| NFC              | Unicode canonical normalization form used for internal text                                   |
| VAD              | Voice activity detection used to locate speech regions                                        |
| WER and CER      | Word and character error rates against fixed references                                       |
| MOS              | Mean opinion score from human listening tests                                                 |
| DER              | Diarization error rate under a declared scoring policy                                        |
| RTF              | Processing duration divided by source or generated audio duration                             |
| LoRA and QLoRA   | Parameter efficient model adaptation approaches                                               |
| GGUF             | Model artifact format used by compatible local inference toolchains                           |
| Model digest     | Cryptographic identity of an exact model artifact                                             |
| SRT and WebVTT   | Caption file formats with time stamped cues                                                   |
| Protected span   | A range the engine cannot change without explicit user targeting                              |
| Offline first    | Core processing and installed features work locally; updates are explicit maintenance actions |
| Quality gate     | A measurable blocking condition for enabling a production capability                          |

## 24.3 Requirement identifier index

CORE covers shared behavior; ASR covers recognition; TTS covers speech
synthesis; CAP covers captions; TRN covers transliteration; FNT covers
legacy conversion; WR covers writing tools; MT covers translation; ADM
covers model administration; INT covers host adapters; TRNPIPE covers
training lifecycle; NFR covers nonfunctional targets; SEC covers privacy
and security. AT identifiers define verification procedures and AC
identifiers define product release outcomes.

Requirement changes shall retain identifiers for unchanged intent.
Removed requirements remain recorded as retired in the change log; IDs
shall not be reassigned to unrelated behavior. New API fields, profiles,
language directions or model package schemas require explicit
compatibility review and affected test updates.
