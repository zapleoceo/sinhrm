import { TestBed } from '@angular/core/testing';
import {
  DESKTOP_WALLPAPER_MAX_DATA_URL_LENGTH,
  DESKTOP_WALLPAPER_STORAGE_KEY,
  DesktopWallpaperStore,
} from './desktop-wallpaper.store';

const pngHeader = new Uint8Array([137, 80, 78, 71, 13, 10, 26, 10]);

function fixtureFile(type = 'image/png'): File {
  return new File([pngHeader], 'wallpaper.png', { type });
}

describe('DesktopWallpaperStore', () => {
  beforeEach(() => {
    localStorage.clear();
    document.documentElement.style.removeProperty('--os-user-wallpaper');
    vi.stubGlobal('createImageBitmap', vi.fn(async () => ({ width: 3000, height: 1800, close: vi.fn() })));
    Object.defineProperty(HTMLCanvasElement.prototype, 'getContext', {
      configurable: true,
      value: vi.fn(() => ({ drawImage: vi.fn() })),
    });
    Object.defineProperty(HTMLCanvasElement.prototype, 'toBlob', {
      configurable: true,
      value: vi.fn((callback: BlobCallback) => callback(new Blob(['image'], { type: 'image/webp' }))),
    });
    TestBed.configureTestingModule({});
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it('validates, resizes, stores, restores and removes a local image without touching theme preferences', async () => {
    const store = TestBed.inject(DesktopWallpaperStore);
    await store.save(fixtureFile());

    const saved = localStorage.getItem(DESKTOP_WALLPAPER_STORAGE_KEY);
    expect(saved).toMatch(/^data:image\/webp;base64,/);
    expect(saved!.length).toBeLessThan(DESKTOP_WALLPAPER_MAX_DATA_URL_LENGTH);
    expect(store.dataUrl()).toBe(saved);
    expect(document.documentElement.style.getPropertyValue('--os-user-wallpaper')).toBe(`url("${saved}")`);
    expect((HTMLCanvasElement.prototype.toBlob as ReturnType<typeof vi.fn>).mock.instances[0]?.width).toBeLessThanOrEqual(2048);

    TestBed.resetTestingModule();
    TestBed.configureTestingModule({});
    const restored = TestBed.inject(DesktopWallpaperStore);
    expect(restored.dataUrl()).toBe(saved);
    expect(document.documentElement.style.getPropertyValue('--os-user-wallpaper')).toContain(saved!);

    restored.remove();
    expect(localStorage.getItem(DESKTOP_WALLPAPER_STORAGE_KEY)).toBeNull();
    expect(restored.dataUrl()).toBeNull();
    expect(document.documentElement.style.getPropertyValue('--os-user-wallpaper')).toBe('');
  });

  it('rejects an unsupported type, a mismatched signature and undecodable image', async () => {
    const store = TestBed.inject(DesktopWallpaperStore);
    await expect(store.save(new File(['<svg/>'], 'bad.svg', { type: 'image/svg+xml' }))).rejects.toThrow('unsupported');
    await expect(store.save(new File(['not png'], 'bad.png', { type: 'image/png' }))).rejects.toThrow('invalid');
    vi.stubGlobal('createImageBitmap', vi.fn(async () => { throw new Error('decode failed'); }));
    await expect(store.save(fixtureFile())).rejects.toThrow('invalid');
    expect(localStorage.getItem(DESKTOP_WALLPAPER_STORAGE_KEY)).toBeNull();
  });

  it('reports storage quota failures and never applies an unpersisted image', async () => {
    const store = TestBed.inject(DesktopWallpaperStore);
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new DOMException('quota', 'QuotaExceededError'); });
    await expect(store.save(fixtureFile())).rejects.toThrow('storage');
    expect(store.dataUrl()).toBeNull();
    expect(document.documentElement.style.getPropertyValue('--os-user-wallpaper')).toBe('');
  });

  it('keeps the newest selection when image decoding completes out of order', async () => {
    const pending: ((bitmap: ImageBitmap) => void)[] = [];
    const loader = vi.fn(() => new Promise<ImageBitmap>((resolve) => pending.push(resolve)));
    vi.stubGlobal('createImageBitmap', loader);
    Object.defineProperty(HTMLCanvasElement.prototype, 'toBlob', {
      configurable: true,
      value: function (this: HTMLCanvasElement, callback: BlobCallback): void {
        callback(new Blob([String(this.width)], { type: 'image/webp' }));
      },
    });
    const store = TestBed.inject(DesktopWallpaperStore);
    const older = store.save(fixtureFile());
    const newer = store.save(fixtureFile());
    await vi.waitFor(() => expect(loader).toHaveBeenCalledTimes(2));
    pending[1]({ width: 20, height: 10, close: vi.fn() } as unknown as ImageBitmap);
    await newer;
    const latest = store.dataUrl();
    pending[0]({ width: 10, height: 10, close: vi.fn() } as unknown as ImageBitmap);
    await older;

    expect(latest).toBe(`data:image/webp;base64,${btoa('20')}`);
    expect(latest).not.toBe(`data:image/webp;base64,${btoa('10')}`);
    expect(store.dataUrl()).toBe(latest);
    expect(localStorage.getItem(DESKTOP_WALLPAPER_STORAGE_KEY)).toBe(latest);
  });

  it('does not restore an image when removal happens during decoding', async () => {
    let resolveBitmap: ((bitmap: ImageBitmap) => void) | undefined;
    const loader = vi.fn(() => new Promise<ImageBitmap>((resolve) => { resolveBitmap = resolve; }));
    vi.stubGlobal('createImageBitmap', loader);
    const store = TestBed.inject(DesktopWallpaperStore);
    const pendingSave = store.save(fixtureFile());
    await vi.waitFor(() => expect(loader).toHaveBeenCalledOnce());
    store.remove();
    resolveBitmap?.({ width: 200, height: 100, close: vi.fn() } as unknown as ImageBitmap);
    await pendingSave;

    expect(store.dataUrl()).toBeNull();
    expect(localStorage.getItem(DESKTOP_WALLPAPER_STORAGE_KEY)).toBeNull();
    expect(document.documentElement.style.getPropertyValue('--os-user-wallpaper')).toBe('');
  });

  it('discards malformed or over-limit persisted values', () => {
    localStorage.setItem(DESKTOP_WALLPAPER_STORAGE_KEY, 'data:image/svg+xml;base64,PHN2Zz4=');
    TestBed.inject(DesktopWallpaperStore);
    expect(localStorage.getItem(DESKTOP_WALLPAPER_STORAGE_KEY)).toBeNull();
    expect(document.documentElement.style.getPropertyValue('--os-user-wallpaper')).toBe('');
  });
});
