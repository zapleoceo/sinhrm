import { DOCUMENT } from '@angular/common';
import { Injectable, inject, signal } from '@angular/core';

export const DESKTOP_WALLPAPER_STORAGE_KEY = 'sinhrm.desktop-wallpaper';
export const DESKTOP_WALLPAPER_MAX_DATA_URL_LENGTH = 1_000_000;
const MAX_INPUT_BYTES = 15 * 1024 * 1024;
const MAX_EDGE = 2048;
const MAX_OUTPUT_BYTES = 700 * 1024;
const OUTPUT_QUALITIES = [0.84, 0.72, 0.6, 0.48] as const;
const acceptedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'] as const;
const dataUrlPattern = /^data:image\/(?:jpeg|png|webp);base64,[A-Za-z0-9+/]+={0,2}$/;

export type WallpaperImageError = 'unsupported' | 'tooLarge' | 'invalid' | 'compression' | 'storage';

@Injectable({ providedIn: 'root' })
export class DesktopWallpaperStore {
  private readonly document = inject(DOCUMENT);
  private latestOperation = 0;
  readonly dataUrl = signal<string | null>(this.readStoredImage());

  constructor() {
    this.applyToDesktop(this.dataUrl());
  }

  async save(file: File): Promise<void> {
    const operation = ++this.latestOperation;
    let output: string;
    try {
      output = await this.process(file);
    } catch (error) {
      if (operation !== this.latestOperation) return;
      throw error;
    }
    if (operation !== this.latestOperation) return;
    try {
      const storage = this.document.defaultView?.localStorage;
      if (!storage) throw new Error('storage unavailable');
      storage.setItem(DESKTOP_WALLPAPER_STORAGE_KEY, output);
    } catch {
      throw new Error('storage' satisfies WallpaperImageError);
    }
    this.dataUrl.set(output);
    this.applyToDesktop(output);
  }

  remove(): void {
    this.latestOperation++;
    try {
      const storage = this.document.defaultView?.localStorage;
      if (!storage) throw new Error('storage unavailable');
      storage.removeItem(DESKTOP_WALLPAPER_STORAGE_KEY);
    } catch {
      throw new Error('storage' satisfies WallpaperImageError);
    }
    this.dataUrl.set(null);
    this.applyToDesktop(null);
  }

  private async process(file: File): Promise<string> {
    if (!(acceptedMimeTypes as readonly string[]).includes(file.type)) throw new Error('unsupported' satisfies WallpaperImageError);
    if (file.size === 0) throw new Error('invalid' satisfies WallpaperImageError);
    if (file.size > MAX_INPUT_BYTES) throw new Error('tooLarge' satisfies WallpaperImageError);
    if (!await this.signatureMatches(file)) throw new Error('invalid' satisfies WallpaperImageError);

    let bitmap: ImageBitmap;
    try {
      if (typeof createImageBitmap !== 'function') throw new Error('decode unavailable');
      bitmap = await createImageBitmap(file);
    } catch {
      throw new Error('invalid' satisfies WallpaperImageError);
    }

    try {
      if (bitmap.width < 1 || bitmap.height < 1) throw new Error('invalid' satisfies WallpaperImageError);
      const initialScale = Math.min(1, MAX_EDGE / Math.max(bitmap.width, bitmap.height));
      let scale = initialScale;
      for (let resizePass = 0; resizePass < 8; resizePass++, scale *= 0.8) {
        const canvas = this.document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(bitmap.width * scale));
        canvas.height = Math.max(1, Math.round(bitmap.height * scale));
        const context = canvas.getContext('2d');
        if (!context) throw new Error('compression' satisfies WallpaperImageError);
        context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);

        for (const quality of OUTPUT_QUALITIES) {
          const blob = await this.toBlob(canvas, quality);
          if (blob.size > MAX_OUTPUT_BYTES || !acceptedMimeTypes.includes(blob.type as typeof acceptedMimeTypes[number])) continue;
          const dataUrl = await this.toDataUrl(blob);
          if (dataUrl.length <= DESKTOP_WALLPAPER_MAX_DATA_URL_LENGTH) return dataUrl;
        }
      }
    } catch (error) {
      if (error instanceof Error && ['invalid', 'compression', 'storage'].includes(error.message)) throw error;
      throw new Error('compression' satisfies WallpaperImageError, { cause: error });
    } finally {
      bitmap.close();
    }
    throw new Error('compression' satisfies WallpaperImageError);
  }

  private async signatureMatches(file: File): Promise<boolean> {
    const bytes = new Uint8Array(await file.slice(0, 12).arrayBuffer());
    if (file.type === 'image/png') return bytes.length >= 8 && [137, 80, 78, 71, 13, 10, 26, 10].every((byte, index) => bytes[index] === byte);
    if (file.type === 'image/jpeg') return bytes.length >= 3 && bytes[0] === 255 && bytes[1] === 216 && bytes[2] === 255;
    return file.type === 'image/webp'
      && bytes.length >= 12
      && String.fromCharCode(...bytes.slice(0, 4)) === 'RIFF'
      && String.fromCharCode(...bytes.slice(8, 12)) === 'WEBP';
  }

  private toBlob(canvas: HTMLCanvasElement, quality: number): Promise<Blob> {
    return new Promise((resolve, reject) => {
      canvas.toBlob((blob) => blob ? resolve(blob) : reject(new Error('compression')), 'image/webp', quality);
    });
  }

  private async toDataUrl(blob: Blob): Promise<string> {
    const bytes = new Uint8Array(await blob.arrayBuffer());
    let binary = '';
    for (let offset = 0; offset < bytes.length; offset += 0x8000) {
      binary += String.fromCharCode(...bytes.subarray(offset, offset + 0x8000));
    }
    return `data:${blob.type};base64,${btoa(binary)}`;
  }

  private readStoredImage(): string | null {
    try {
      const stored = this.document.defaultView?.localStorage.getItem(DESKTOP_WALLPAPER_STORAGE_KEY) ?? null;
      if (stored === null || stored.length <= DESKTOP_WALLPAPER_MAX_DATA_URL_LENGTH && dataUrlPattern.test(stored)) return stored;
      this.document.defaultView?.localStorage.removeItem(DESKTOP_WALLPAPER_STORAGE_KEY);
    } catch {
      // A blocked or malformed local preference should not stop the desktop from rendering.
    }
    return null;
  }

  private applyToDesktop(dataUrl: string | null): void {
    const style = this.document.documentElement.style;
    if (dataUrl && dataUrl.length <= DESKTOP_WALLPAPER_MAX_DATA_URL_LENGTH && dataUrlPattern.test(dataUrl)) {
      style.setProperty('--os-user-wallpaper', `url("${dataUrl}")`);
    } else {
      style.removeProperty('--os-user-wallpaper');
    }
  }
}
