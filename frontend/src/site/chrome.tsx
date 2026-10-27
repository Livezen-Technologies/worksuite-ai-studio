/** Public site chrome (Radiant): the plus-grid navbar, the call-to-action footer, and the frame every page shares. */
import { Disclosure, DisclosureButton, DisclosurePanel } from '@headlessui/react';
import { Bars2Icon } from '@heroicons/react/24/solid';
import { motion } from 'framer-motion';
import { useEffect, type ReactNode } from 'react';
import { useAuth } from '../lib/auth';
import { Button, Container, Gradient, Link, PlusGrid, PlusGridItem, PlusGridRow, Subheading } from './ui';

/** The original WorkSuite logo with the product name, sized for the site. */
export function SiteLogo({ className = '' }: { className?: string }) {
  return (
    <span className={`flex items-center gap-3 ${className}`}>
      <img src="/brand/worksuite-wordmark.png" alt="WorkSuite" className="h-8 w-auto" />
      <span className="border-l border-gray-950/15 pl-3 text-lg font-medium tracking-tight whitespace-nowrap text-gray-950">AI Studio</span>
    </span>
  );
}

function useLinks() {
  const { user } = useAuth();
  return [
    { href: '/ai-model-training', label: 'Model training' },
    { href: '/pricing', label: 'Pricing' },
    { href: '/company', label: 'Company' },
    { href: '/blog', label: 'Blog' },
    user ? { href: '/', label: 'Open the app' } : { href: '/login', label: 'Sign in' },
  ];
}

export function Navbar({ banner }: { banner?: ReactNode }) {
  const links = useLinks();
  return (
    <Disclosure as="header" className="pt-12 sm:pt-16">
      <PlusGrid>
        <PlusGridRow className="relative flex justify-between">
          <div className="relative flex gap-6">
            <PlusGridItem className="py-3">
              <Link href="/" title="AI Studio home"><SiteLogo /></Link>
            </PlusGridItem>
            {banner && <div className="relative hidden items-center py-3 lg:flex">{banner}</div>}
          </div>
          <nav className="relative hidden lg:flex">
            {links.map(({ href, label }) => (
              <PlusGridItem key={label} className="relative flex">
                <Link href={href} className="flex items-center px-4 py-3 text-base font-medium text-gray-950 bg-blend-multiply data-hover:bg-black/2.5">{label}</Link>
              </PlusGridItem>
            ))}
          </nav>
          <DisclosureButton className="flex size-12 items-center justify-center self-center rounded-lg data-hover:bg-black/5 lg:hidden" aria-label="Open main menu">
            <Bars2Icon className="size-6" />
          </DisclosureButton>
        </PlusGridRow>
      </PlusGrid>
      <DisclosurePanel className="lg:hidden">
        <div className="flex flex-col gap-6 py-4">
          {links.map(({ href, label }, i) => (
            <motion.div key={label} initial={{ opacity: 0, rotateX: -90 }} animate={{ opacity: 1, rotateX: 0 }}
              transition={{ duration: 0.15, ease: 'easeInOut', rotateX: { duration: 0.3, delay: i * 0.1 } }}>
              <Link href={href} className="text-base font-medium text-gray-950">{label}</Link>
            </motion.div>
          ))}
        </div>
        <div className="absolute left-1/2 w-screen -translate-x-1/2">
          <div className="absolute inset-x-0 top-0 border-t border-black/5" />
          <div className="absolute inset-x-0 top-2 border-t border-black/5" />
        </div>
      </DisclosurePanel>
    </Disclosure>
  );
}

function SitemapColumn({ title, links }: { title: string; links: [string, string][] }) {
  return (
    <div>
      <h3 className="text-sm/6 font-medium text-gray-950/50">{title}</h3>
      <ul className="mt-6 space-y-4 text-sm/6">
        {links.map(([label, href]) => (
          <li key={label}><Link href={href} className="font-medium text-gray-950 data-hover:text-gray-950/75">{label}</Link></li>
        ))}
      </ul>
    </div>
  );
}

export function Footer() {
  const { user } = useAuth();
  return (
    <footer>
      <Gradient className="relative">
        <div className="absolute inset-2 rounded-4xl bg-white/80" />
        <Container>
          <div className="relative pt-20 pb-16 text-center sm:py-24">
            <hgroup>
              <Subheading>Get started</Subheading>
              <p className="mt-6 text-3xl font-medium tracking-tight text-gray-950 sm:text-5xl">
                Build AI that speaks Sinhala.
                <br />
                On your own servers.
              </p>
            </hgroup>
            <p className="mx-auto mt-6 max-w-sm text-sm/6 text-gray-500">
              Chat, speech, datasets and training in one control plane — with every model and every byte of data kept where you decide.
            </p>
            <div className="mt-6">
              <Button className="w-full sm:w-auto" href={user ? '/' : '/login'}>{user ? 'Open the app' : 'Sign in'}</Button>
            </div>
          </div>
          <PlusGrid className="pb-16">
            <PlusGridRow>
              <div className="grid grid-cols-2 gap-y-10 pb-6 lg:grid-cols-6 lg:gap-8">
                <div className="col-span-2 flex">
                  <PlusGridItem className="pt-6 lg:pb-6"><SiteLogo /></PlusGridItem>
                </div>
                <div className="col-span-2 grid grid-cols-2 gap-x-8 gap-y-12 lg:col-span-4 lg:grid-cols-subgrid lg:pt-6">
                  <SitemapColumn title="Product" links={[['Model training', '/ai-model-training'], ['Pricing', '/pricing'], ['Chat', '/chat'], ['Speech to text', '/speech'], ['Training', '/training']]} />
                  <SitemapColumn title="Company" links={[['About', '/company'], ['Blog', '/blog']]} />
                  <SitemapColumn title="Developers" links={[['API keys', '/api-keys'], ['Engine', '/engine']]} />
                  <SitemapColumn title="Account" links={user ? [['Open the app', '/']] : [['Sign in', '/login']]} />
                </div>
              </div>
            </PlusGridRow>
            <PlusGridRow className="flex justify-between">
              <PlusGridItem className="py-3"><p className="text-sm/6 text-gray-950">© 2026 Livezen Technologies</p></PlusGridItem>
              <PlusGridItem className="py-3"><p className="text-sm/6 text-gray-500">Made in Sri Lanka</p></PlusGridItem>
            </PlusGridRow>
          </PlusGrid>
        </Container>
      </Gradient>
    </footer>
  );
}

/** Every public page: Switzer, white ground, a title, and the page at the top on navigation. */
export function SitePage({ title, children }: { title: string; children: ReactNode }) {
  useEffect(() => {
    document.title = title;
    window.scrollTo(0, 0);
  }, [title]);
  // Light-only by design (Radiant); its own white ground covers the app's dark body, and the app's theme is untouched.
  return <div className="site min-h-full overflow-hidden bg-white font-[Switzer,_ui-sans-serif,_system-ui,_sans-serif] text-gray-950 antialiased">{children}</div>;
}
