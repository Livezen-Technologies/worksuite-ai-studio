declare module 'virtual:latest-release' {
  const latest: { version: string; date: string; title: string } | null;
  export default latest;
}
