import { CheckIcon, MinusIcon } from '@heroicons/react/16/solid';
import { Footer, Navbar, SitePage } from '../chrome';
import { Button, Container, Gradient, GradientBackground, Heading, Lead, Subheading } from '../ui';

/**
 * Plans by how AI Studio is deployed. Prices are set per organisation (servers, GPUs, support), so every plan says
 * "Talk to us" until Livezen publishes list prices — edit TIERS when it does.
 */
const TIERS = [
  {
    name: 'Starter', slug: 'starter', price: 'Talk to us', description: 'One server with chat and speech for a small team.',
    highlights: ['Chat with Sinhala-tuned models', 'Speech to text with captions', 'Datasets and the model library', 'API keys for your own apps'],
  },
  {
    name: 'Team', slug: 'team', price: 'Talk to us', description: 'Your own GPU nodes and training, for teams building their own models.',
    highlights: ['Everything in Starter', 'GPU and CPU nodes over secure tunnels', 'Custom LLMs and speech fine-tunes', 'Automated training pipelines'],
  },
  {
    name: 'Enterprise', slug: 'enterprise', price: 'Talk to us', description: 'On-premises, air-gapped options and release governance.',
    highlights: ['Everything in Team', 'Engine registry with release gates', 'Two-person approvals and signed packages', 'Priority support from Livezen'],
  },
] as const;

type Tier = (typeof TIERS)[number]['slug'];
const FEATURES: { section: string; name: string; value: Record<Tier, boolean | string> }[] = [
  { section: 'Use', name: 'Chat (Sinhala, Singlish, English)', value: { starter: true, team: true, enterprise: true } },
  { section: 'Use', name: 'Speech to text, live and uploads', value: { starter: true, team: true, enterprise: true } },
  { section: 'Use', name: 'Captions (SRT / VTT)', value: { starter: true, team: true, enterprise: true } },
  { section: 'Use', name: 'Knowledge bases and agents', value: { starter: true, team: true, enterprise: true } },
  { section: 'Build', name: 'Datasets with rights and approvals', value: { starter: true, team: true, enterprise: true } },
  { section: 'Build', name: 'Custom LLMs (LoRA → GGUF → Ollama)', value: { starter: false, team: true, enterprise: true } },
  { section: 'Build', name: 'Speech model fine-tuning', value: { starter: false, team: true, enterprise: true } },
  { section: 'Build', name: 'Automated training pipelines', value: { starter: false, team: true, enterprise: true } },
  { section: 'Run', name: 'Nodes', value: { starter: 'This server', team: 'GPU + CPU nodes', enterprise: 'Unlimited' } },
  { section: 'Run', name: 'GPU transcription on nodes', value: { starter: false, team: true, enterprise: true } },
  { section: 'Run', name: 'API gateway with keys and quotas', value: { starter: true, team: true, enterprise: true } },
  { section: 'Govern', name: 'Engine registry and release gates', value: { starter: false, team: false, enterprise: true } },
  { section: 'Govern', name: 'Passkeys and two-factor sign-in', value: { starter: true, team: true, enterprise: true } },
  { section: 'Govern', name: 'Audit log', value: { starter: true, team: true, enterprise: true } },
  { section: 'Support', name: 'Support', value: { starter: 'Email', team: 'Email + onboarding', enterprise: 'Priority' } },
];

function PricingCard({ tier }: { tier: (typeof TIERS)[number] }) {
  return (
    <div className="-m-2 grid grid-cols-1 rounded-4xl shadow-[inset_0_0_2px_1px_#ffffff4d] ring-1 ring-black/5 max-lg:mx-auto max-lg:w-full max-lg:max-w-md">
      <div className="grid grid-cols-1 rounded-4xl p-2 shadow-md shadow-black/5">
        <div className="rounded-3xl bg-white p-10 pb-9 shadow-2xl ring-1 ring-black/5">
          <Subheading>{tier.name}</Subheading>
          <p className="mt-2 text-sm/6 text-gray-950/75">{tier.description}</p>
          <div className="mt-8 text-4xl font-medium text-gray-950">{tier.price}</div>
          <p className="mt-1 text-sm/5 text-gray-950/75">Priced for your servers, nodes and support</p>
          <div className="mt-8"><Button href="/company#contact">Talk to Livezen</Button></div>
          <div className="mt-8">
            <h3 className="text-sm/6 font-medium text-gray-950">Includes:</h3>
            <ul className="mt-3 space-y-3">
              {tier.highlights.map((h) => (
                <li key={h} className="flex items-start gap-4 text-sm/6 text-gray-950/75">
                  <span className="inline-flex h-6 items-center"><CheckIcon className="size-4 fill-gray-950/40" aria-hidden /></span>{h}
                </li>
              ))}
            </ul>
          </div>
        </div>
      </div>
    </div>
  );
}

function Table() {
  const sections = [...new Set(FEATURES.map((f) => f.section))];
  return (
    <Container className="py-24">
      <div className="overflow-x-auto">
        <table className="w-full min-w-[640px] text-left">
          <caption className="sr-only">Plan comparison</caption>
          <colgroup><col className="w-2/5" /><col className="w-1/5" /><col className="w-1/5" /><col className="w-1/5" /></colgroup>
          <thead>
            <tr>
              <td className="p-0" />
              {TIERS.map((t) => <th key={t.slug} scope="col" className="p-0"><Subheading as="div">{t.name}</Subheading></th>)}
            </tr>
          </thead>
          {sections.map((section) => (
            <tbody key={section} className="group">
              <tr>
                <th scope="colgroup" colSpan={4} className="px-0 pt-10 pb-0 group-first-of-type:pt-5">
                  <div className="-mx-4 rounded-lg bg-gray-50 px-4 py-3 text-sm/6 font-semibold">{section}</div>
                </th>
              </tr>
              {FEATURES.filter((f) => f.section === section).map((f) => (
                <tr key={f.name} className="border-b border-gray-100 last:border-none">
                  <th scope="row" className="px-0 py-4 text-sm/6 font-normal text-gray-600">{f.name}</th>
                  {TIERS.map((t) => {
                    const v = f.value[t.slug];
                    return (
                      <td key={t.slug} className="p-4 max-sm:text-center">
                        {v === true ? <><CheckIcon className="size-4 fill-green-600" aria-hidden /><span className="sr-only">Included in {t.name}</span></>
                          : v === false ? <><MinusIcon className="size-4 fill-gray-400" aria-hidden /><span className="sr-only">Not in {t.name}</span></>
                          : <div className="text-sm/6">{v}</div>}
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          ))}
        </table>
      </div>
    </Container>
  );
}

const FAQ: [string, string][] = [
  ['Where does my data live?', 'On your servers. AI Studio runs on your own machine and your nodes; datasets, recordings and models never go to a third-party AI service unless you add one yourself.'],
  ['Do I need a GPU?', 'No. Chat and speech run on CPU. A GPU node makes speech several times faster and is needed to train custom models — add one with a single command when you are ready.'],
  ['Which models can I use?', 'Any model Ollama runs, Sinhala-tuned chat models, Whisper Sinhala for speech, and your own custom LLMs built in the Studio.'],
  ['Are the models free to use commercially?', 'Each model and dataset carries its own licence in the Studio. Some Sinhala models and datasets are non-commercial; the catalog shows which.'],
];

export default function Pricing() {
  return (
    <SitePage title="Plans — AI Studio">
      <GradientBackground />
      <Container><Navbar /></Container>
      <Container className="mt-16">
        <Heading as="h1">Plans that fit how you run AI.</Heading>
        <Lead className="mt-6 max-w-3xl">From one server with chat and speech to a fleet of GPU nodes training your own models — priced for your setup.</Lead>
      </Container>
      <div className="relative py-24">
        <Gradient className="absolute inset-x-2 top-48 bottom-0 rounded-4xl ring-1 ring-black/5 ring-inset" />
        <Container className="relative">
          <div className="grid grid-cols-1 gap-8 lg:grid-cols-3">{TIERS.map((t) => <PricingCard key={t.slug} tier={t} />)}</div>
        </Container>
      </div>
      <Table />
      <Container className="pb-24">
        <section className="scroll-mt-8">
          <Subheading className="text-center">Questions</Subheading>
          <Heading as="div" className="mt-2 text-center">Good to know.</Heading>
          <div className="mx-auto mt-16 mb-32 max-w-xl space-y-12">
            {FAQ.map(([q, a]) => (
              <dl key={q}>
                <dt className="text-sm font-semibold">{q}</dt>
                <dd className="mt-4 text-sm/6 text-gray-600">{a}</dd>
              </dl>
            ))}
          </div>
        </section>
      </Container>
      <Footer />
    </SitePage>
  );
}
