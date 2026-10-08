import { openTablePage } from '../../../testing/table-page';
import { ModuleOffPage } from './module-off.page';

/** «Розділ вимкнено» — the page moduleGuard sends a switched-off module to (/module-off?module=<key>). */
describe('ModuleOffPage', () => {
  it('names the module from ?module= and links back home', async () => {
    const { el } = await openTablePage(ModuleOffPage, '/?module=perform');
    expect(el.querySelector('h1')?.textContent?.trim()).toBe('modules.off.title');
    expect(el.querySelector('p.module')?.textContent?.trim()).toBe('modules.names.perform');
    expect(el.querySelector('a')?.getAttribute('href')).toBe('/');
  });

  it('without ?module= there is no module line, only the explanation', async () => {
    const { el } = await openTablePage(ModuleOffPage, '/');
    expect(el.querySelector('p.module')).toBeNull();
    expect(el.textContent).toContain('modules.off.text');
  });

  it('markup in ?module= stays text (it only builds a translation key)', async () => {
    const { el } = await openTablePage(ModuleOffPage, '/?module=%3Cimg%20src%3Dx%3E');
    expect(el.querySelector('p.module img')).toBeNull();
    expect(el.querySelector('p.module')?.textContent).toContain('<img src=x>');
  });
});
