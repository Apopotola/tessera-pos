/**
 * Shrinks a photo in the browser before upload, so any size from a phone or camera works:
 * the longest side becomes at most `maxSide` px and it is re-encoded as WebP (JPEG if the
 * browser cannot write WebP). Smaller photos keep their size. SVG and unreadable files are
 * returned unchanged for the server to accept or refuse.
 */
export async function shrinkImage(file: File, maxSide = 1200, quality = 0.85): Promise<File> {
  if (file.type === "image/svg+xml" || typeof createImageBitmap !== "function") return file;

  let bitmap: ImageBitmap;
  try {
    bitmap = await createImageBitmap(file, { imageOrientation: "from-image" });
  } catch {
    return file;
  }

  const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
  const width = Math.max(1, Math.round(bitmap.width * scale));
  const height = Math.max(1, Math.round(bitmap.height * scale));

  const canvas = document.createElement("canvas");
  canvas.width = width;
  canvas.height = height;
  const context = canvas.getContext("2d");
  if (!context) {
    bitmap.close();
    return file;
  }
  context.imageSmoothingQuality = "high";
  context.drawImage(bitmap, 0, 0, width, height);
  bitmap.close();

  const encode = (type: string) => new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, type, quality));
  const blob = (await encode("image/webp")) ?? (await encode("image/jpeg"));
  if (!blob || (scale === 1 && blob.size >= file.size)) return file;

  const extension = blob.type === "image/webp" ? "webp" : "jpg";
  const name = `${file.name.replace(/\.[^.]+$/, "") || "photo"}.${extension}`;

  return new File([blob], name, { type: blob.type, lastModified: Date.now() });
}
