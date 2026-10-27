import { CheckIcon } from '@heroicons/react/16/solid';
import { useAuth } from '../../lib/auth';
import { Footer, Navbar, SitePage } from '../chrome';
import { DatasetsGraphic, GatesGraphic, NetworkGraphic, SpeechGraphic, TrainingCard } from '../mocks';
import { BentoCard, Button, Container, Gradient, Heading, Lead, Subheading } from '../ui';

/** What you can train: each is a real recipe in AI Studio. */
const KINDS: { eyebrow: string; title: string; text: string; points: string[] }[] = [
  {
    eyebrow: 'Custom LLM', title: 'A chat model that knows your work',
    text: 'Fine-tune an open model — Qwen2.5 in 0.5B to 3B, or any Hugging Face model — on your instruction data. The Studio merges it, converts it to GGUF and puts it in Chat under the name you choose.',
    points: ['LoRA training on your GPU nodes', 'Quantized for fast CPU or GPU serving', 'Your system prompt built in'],
  },
  {
    eyebrow: 'Text classifier', title: 'Sort text into your categories',
    text: 'Route tickets, tag news, detect intent or sentiment in Sinhala and English. Give it labelled examples; it learns your labels and answers through an API in well under a second.',
    points: ['XLM-RoBERTa for Sinhala + English', 'Accuracy and macro-F1 on held-out rows', 'POST /api/v1/classify for your apps'],
  },
  {
    eyebrow: 'Speech model', title: 'Speech recognition for your voices and words',
    text: 'Upload recordings with their transcripts — your accents, product names, place names — and fine-tune Whisper Sinhala on them, gently enough that everyday Sinhala stays as good as before.',
    points: ['Zip of recordings + a transcript list', 'Scored on your clips and on general Sinhala', 'Appears in Speech and on GPU nodes'],
  },
  {
    eyebrow: 'Coming next', title: 'Predictions from your tables',
    text: 'Forecast sales, flag churn or score leads from spreadsheet data: choose the column to predict, and the Studio trains and compares classic machine-learning models for you.',
    points: ['CSV or Excel in, a prediction API out', 'Automatic model comparison', 'Feature importance you can read'],
  },
];

const STEPS: { title: string; text: string; real: string }[] = [
  { title: 'Bring your data', text: 'Upload instruction pairs, labelled text or recordings. Each dataset keeps its source, licence and approval, and is cleaned and de-duplicated.', real: 'Our own Sinhala mix: 14.1M rows from 30 sources, balanced by category.' },
  { title: 'Check it before training', text: 'The Studio validates the data first — rights approved, enough rows, real labels rather than free text, recordings with transcripts — and stops with the reason if something is off.', real: 'A capped run samples evenly across the whole dataset, not just its first rows.' },
  { title: 'Train on your own machines', text: 'A training agent on each node picks up the job, downloads only what it needs over an encrypted connection, and trains on every GPU it has.', real: 'Whisper Sinhala on 2 × Tesla T4: 3 passes in about 15 minutes.' },
  { title: 'Measure on held-out data', text: 'Every run is scored on rows it never trained on, before and after, and a run that makes things worse is not the one kept.', real: 'Speech models are scored on your clips and on general Sinhala, so forgetting shows.' },
  { title: 'Ship it where people work', text: 'Chat models go into Chat and the API, classifiers into the classify API, speech models into the Speech page and the GPU nodes — automatically.', real: 'Adapter to a working Ollama model in 46 seconds for a small model.' },
  { title: 'Keep it current', text: 'Leave a pipeline on Automatic and it retrains when its data changes, with live progress, retries and a notification when it is done.', real: 'Release gates and two-person approval before a model goes into production.' },
];

const REASONS: [string, string][] = [
  ['Your data never leaves', 'Datasets, recordings and models stay on your servers and nodes. Locked test splits never leave the Studio at all.'],
  ['Sinhala first', 'Recipes, tokenizers and evaluation are tuned for Sinhala and Singlish, not translated from English afterwards.'],
  ['Honest numbers', 'Before-and-after scores on held-out data for every run — including when a model got worse, so you never ship one blind.'],
  ['Any machine you have', 'GPU servers, CPU servers and workstations join with one command. Training runs on all the GPUs a node has.'],
  ['No notebooks to babysit', 'From data to a model in Chat, Speech or the API without copying files, converting weights or editing configs by hand.'],
  ['Rights you can prove', 'Every dataset and model records its licence; non-commercial material stays out of commercial models.'],
];

const FAQ: [string, string][] = [
  ['How much data do I need?', 'It depends on the job. A classifier can work from a few hundred labelled examples per label; a speech model needs at least 50 recordings, and a few hundred make a real difference; a chat model benefits from thousands of instruction pairs.'],
  ['Do I need a GPU?', 'Text classifiers train on CPU. Chat models and speech models need an NVIDIA GPU — a single 16 GB card such as a Tesla T4 is enough for the models listed here.'],
  ['Who owns the trained model?', 'You do. It is built on your servers from your data; the base model and dataset licences still apply, and the Studio shows them on every model.'],
  ['What if a training run fails?', 'The run says why on the Training page. A crash or a node going quiet is retried automatically; if training finished but the upload failed, a retry uploads the result instead of training again.'],
];

export default function ModelTraining() {
  const { user } = useAuth();
  return (
    <SitePage title="AI model training — AI Studio">
      <div className="relative">
        <Gradient className="absolute inset-2 bottom-0 rounded-4xl ring-1 ring-black/5 ring-inset" />
        <Container className="relative">
          <Navbar />
          <div className="pt-16 pb-24 sm:pt-24 sm:pb-32">
            <Subheading>AI model training</Subheading>
            <h1 className="mt-4 max-w-4xl text-5xl/[1] font-medium tracking-tight text-balance text-gray-950 sm:text-7xl/[0.95]">Train AI models on your data, on your hardware.</h1>
            <p className="mt-8 max-w-xl text-xl/7 font-medium text-gray-950/75">
              Chat models, text classifiers and speech recognition — trained on your GPU nodes from your own datasets and recordings, measured before they ship, and put straight to work.
            </p>
            <div className="mt-12 flex flex-col gap-x-6 gap-y-4 sm:flex-row">
              <Button href={user ? '/training' : '/login'}>{user ? 'Open Training' : 'Sign in'}</Button>
              <Button variant="secondary" href="/company#contact">Talk to Livezen</Button>
            </div>
          </div>
        </Container>
      </div>

      <main>
        <Container className="mt-24">
          <Subheading>What you can train</Subheading>
          <Heading as="h2" className="mt-2 max-w-3xl">One place for every kind of model.</Heading>
          <div className="mt-12 grid grid-cols-1 gap-6 md:grid-cols-2">
            {KINDS.map((k) => (
              <div key={k.title} className="rounded-4xl bg-white p-2 shadow-md ring-1 shadow-black/5 ring-black/5">
                <div className="flex h-full flex-col rounded-3xl p-8 ring-1 ring-black/5">
                  <Subheading as="h3">{k.eyebrow}</Subheading>
                  <p className="mt-2 text-2xl/8 font-medium tracking-tight text-gray-950">{k.title}</p>
                  <p className="mt-3 flex-1 text-sm/6 text-gray-600">{k.text}</p>
                  <ul className="mt-6 space-y-2">
                    {k.points.map((p) => (
                      <li key={p} className="flex items-start gap-3 text-sm/6 text-gray-950/75"><CheckIcon className="mt-1 size-4 shrink-0 fill-gray-950/40" aria-hidden />{p}</li>
                    ))}
                  </ul>
                </div>
              </div>
            ))}
          </div>
        </Container>

        <div className="mt-32 bg-linear-to-b from-white from-50% to-gray-100 py-24">
          <Container>
            <Subheading>How it works</Subheading>
            <Heading as="h2" className="mt-2 max-w-3xl">From raw data to a working model in six steps.</Heading>
            <ol className="mt-16 grid grid-cols-1 gap-x-12 gap-y-14 sm:grid-cols-2 lg:grid-cols-3">
              {STEPS.map((s, i) => (
                <li key={s.title} className="border-t border-black/10 pt-6">
                  <p className="font-mono text-xs/5 font-semibold tracking-widest text-gray-500">STEP {i + 1}</p>
                  <h3 className="mt-2 text-lg/7 font-medium text-gray-950">{s.title}</h3>
                  <p className="mt-3 text-sm/6 text-gray-600">{s.text}</p>
                  <p className="mt-4 rounded-lg bg-white/70 px-3 py-2 text-xs/5 text-gray-700 ring-1 ring-black/5">{s.real}</p>
                </li>
              ))}
            </ol>
          </Container>
        </div>

        <div className="mx-2 mt-2 rounded-4xl bg-gray-900 py-32">
          <Container>
            <Subheading dark>Under the hood</Subheading>
            <Heading as="h2" dark className="mt-2 max-w-3xl">Built for runs you can trust.</Heading>
            <div className="mt-10 grid grid-cols-1 gap-4 sm:mt-16 lg:grid-cols-6 lg:grid-rows-2">
              <BentoCard dark eyebrow="Nodes" title="Every GPU you have" description="Training agents on your servers take jobs over an encrypted tunnel; nothing on them is opened to the internet." graphic={<NetworkGraphic />} className="max-lg:rounded-t-4xl lg:col-span-3 lg:rounded-tl-4xl" />
              <BentoCard dark eyebrow="Live" title="Watch it learn" description="Progress, loss, ETA and GPU load stream to the Training page while the run goes." graphic={<div className="absolute inset-x-8 top-8"><TrainingCard dark /></div>} fade={['bottom']} className="lg:col-span-3 lg:rounded-tr-4xl" />
              <BentoCard dark eyebrow="Quality" title="Gates before release" description="Measured thresholds on held-out data decide what ships." graphic={<GatesGraphic />} className="lg:col-span-6 max-lg:rounded-b-4xl lg:rounded-b-4xl" />
            </div>
          </Container>
        </div>

        <Container className="mt-32">
          <Subheading>Why AI Studio</Subheading>
          <Heading as="h2" className="mt-2 max-w-3xl">Training without the guesswork.</Heading>
          <div className="mt-16 grid grid-cols-1 gap-x-12 gap-y-12 sm:grid-cols-2 lg:grid-cols-3">
            {REASONS.map(([t, d]) => (
              <div key={t} className="border-t border-black/10 pt-6">
                <h3 className="text-lg/7 font-medium text-gray-950">{t}</h3>
                <p className="mt-3 text-sm/6 text-gray-600">{d}</p>
              </div>
            ))}
          </div>
        </Container>

        <Container className="mt-32 grid grid-cols-1 gap-12 lg:grid-cols-2">
          <div className="relative h-80 overflow-hidden rounded-4xl bg-white shadow-md ring-1 ring-black/5"><DatasetsGraphic /></div>
          <div className="relative h-80 overflow-hidden rounded-4xl bg-white shadow-md ring-1 ring-black/5"><SpeechGraphic /></div>
        </Container>

        <Container className="my-32">
          <Subheading className="text-center">Questions</Subheading>
          <Heading as="div" className="mt-2 text-center">Good to know.</Heading>
          <Lead className="mx-auto mt-6 max-w-2xl text-center text-xl">The short answers. Sign in and open Training to try a run on your own data.</Lead>
          <div className="mx-auto mt-16 max-w-xl space-y-12">
            {FAQ.map(([q, a]) => (
              <dl key={q}><dt className="text-sm font-semibold">{q}</dt><dd className="mt-4 text-sm/6 text-gray-600">{a}</dd></dl>
            ))}
          </div>
        </Container>
      </main>
      <Footer />
    </SitePage>
  );
}
