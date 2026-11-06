import { describe, expect, it } from 'vitest';
import { safeHref } from './Markdown';

describe('safeHref', () => {
  it.each(['https://example.com/a?b=1#c', 'http://example.com', 'HTTPS://EXAMPLE.COM', 'mailto:a@b.co'])('keeps %s', (h) => {
    expect(safeHref(h)).toBe(h);
  });
  it.each([
    'javascript:alert(1)', 'JavaScript:alert(1)', 'JAVASCRIPT:alert(1)', ' javascript:alert(1)', '\tjavascript:alert(1)',
    'java\tscript:alert(1)', 'java\nscript:alert(1)', '\x01javascript:alert(1)', 'javascript&colon;alert(1)',
    'data:text/html;base64,PHNjcmlwdD4=', 'DATA:text/html,x', 'vbscript:msgbox(1)', 'VBScript:msgbox(1)',
    'https:javascript:alert(1)', 'https:\x00//x', 'http://exa\tmple.com', '//evil.example', '/relative/path', 'relative', '#anchor', '',
  ])('drops %j', (h) => {
    expect(safeHref(h)).toBeUndefined();
  });
});
