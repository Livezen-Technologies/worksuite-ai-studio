export function slugify(s: string) {
  return s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 80);
}

/** Blog post slug for a release, shared by the blog and the landing page banner. */
export const releaseSlug = (version: string, title: string) => `${slugify(version)}-${slugify(title.trim())}`;
