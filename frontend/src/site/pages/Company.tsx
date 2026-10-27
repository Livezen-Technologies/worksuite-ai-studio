import { useAuth } from '../../lib/auth';
import { Footer, Navbar, SitePage } from '../chrome';
import { COMPANY, CONTACT_EMAIL, CONTACT_PHONE, COUNTRY } from '../config';
import { AnimatedNumber, Button, Container, GradientBackground, Heading, Lead, Subheading } from '../ui';

const VALUES: [string, string][] = [
  ['Sinhala first', 'Most AI is built for English and translated later. We start with Sinhala — how people actually speak and type it, Singlish included — and add English alongside.'],
  ['Yours to run', 'Models, data and recordings stay on your servers. You decide what is trained, where it runs and who can call it.'],
  ['Measured, not promised', 'Every model is scored on held-out data before it ships, and we publish what we measured — including when a model got worse.'],
  ['Clear about rights', 'Every dataset and model carries its source and licence, and the Studio keeps non-commercial material out of commercial use.'],
];

function Header() {
  return (
    <Container className="mt-16">
      <Heading as="h1">Building AI that speaks Sinhala.</Heading>
      <Lead className="mt-6 max-w-3xl">
        AI Studio is made by {COMPANY} in {COUNTRY} — part of WorkSuite, our platform for running a business on your own infrastructure.
      </Lead>
      <section className="mt-16 grid grid-cols-1 lg:grid-cols-2 lg:gap-12">
        <div className="max-w-lg">
          <h2 className="text-2xl font-medium tracking-tight">Our mission</h2>
          <p className="mt-6 text-sm/6 text-gray-600">
            Sri Lankan businesses, schools and offices work in Sinhala every day, yet the AI tools they are offered read and write it poorly and send
            everything to servers abroad. We are building the alternative: chat, speech recognition and model training that understand Sinhala,
            run on hardware you control, and get better with your own data.
          </p>
          <p className="mt-8 text-sm/6 text-gray-600">
            AI Studio brings it together in one place — curated Sinhala datasets with their rights recorded, fine-tuning on your own GPUs, a
            speech engine that writes numbers as digits, and a gateway so your other software can use it all.
          </p>
        </div>
        <div className="max-lg:mt-16 lg:col-span-1">
          <Subheading>The numbers</Subheading>
          <hr className="mt-6 border-t border-gray-200" />
          <dl className="mt-6 grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-2">
            {[[14.1, 1, 'M', 'Training rows'], [1.07, 2, 'M', 'Speech recordings'], [30, 0, '', 'Curated datasets'], [14, 0, '', 'Model packages']].map(([n, d, s, l]) => (
              <div key={l as string} className="flex flex-col gap-y-2 border-b border-dotted border-gray-200 pb-4">
                <dt className="text-sm/6 text-gray-600">{l}</dt>
                <dd className="order-first text-6xl font-medium tracking-tight"><AnimatedNumber start={0} end={n as number} decimals={d as number} />{s}</dd>
              </div>
            ))}
          </dl>
        </div>
      </section>
    </Container>
  );
}

function Values() {
  return (
    <Container className="mt-32">
      <Subheading>What we believe</Subheading>
      <Heading as="h3" className="mt-2 max-w-3xl">How we build.</Heading>
      <div className="mt-16 grid grid-cols-1 gap-x-12 gap-y-12 sm:grid-cols-2">
        {VALUES.map(([t, d]) => (
          <div key={t} className="border-t border-black/10 pt-8">
            <h3 className="text-lg/7 font-medium text-gray-950">{t}</h3>
            <p className="mt-3 text-sm/6 text-gray-600">{d}</p>
          </div>
        ))}
      </div>
    </Container>
  );
}

function Contact() {
  const { user } = useAuth();
  return (
    <Container className="my-32">
      <section id="contact" className="scroll-mt-16 rounded-4xl bg-gray-900 px-8 py-16 text-white sm:px-16">
        <Subheading dark>Contact</Subheading>
        <Heading as="h3" dark className="mt-2 max-w-2xl">Talk to {COMPANY}.</Heading>
        <p className="mt-6 max-w-xl text-sm/6 text-gray-400">
          Plans are priced for your servers, nodes and support. Tell us what you want to run and we will set it up with you.
        </p>
        <div className="mt-10 flex flex-col gap-4 sm:flex-row">
          {CONTACT_EMAIL && <Button href={`mailto:${CONTACT_EMAIL}`} className="bg-white! text-gray-950!">{CONTACT_EMAIL}</Button>}
          {CONTACT_PHONE && <Button variant="secondary" href={`tel:${CONTACT_PHONE.replace(/\s/g, '')}`} className="text-white!">{CONTACT_PHONE}</Button>}
          {!CONTACT_EMAIL && !CONTACT_PHONE && (
            <Button href={user ? '/' : '/login'} className="bg-white! text-gray-950!">{user ? 'Open the app' : 'Sign in to get in touch'}</Button>
          )}
        </div>
      </section>
    </Container>
  );
}

export default function Company() {
  return (
    <SitePage title="Company — AI Studio">
      <GradientBackground />
      <Container><Navbar /></Container>
      <Header />
      <Values />
      <Contact />
      <Footer />
    </SitePage>
  );
}
