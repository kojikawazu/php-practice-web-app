/** 画像アップロード用の最小 PNG（1x1・透明）をメモリ上に生成する。 */
const BASE64_1PX_PNG =
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

export function pngBuffer(): Buffer {
  return Buffer.from(BASE64_1PX_PNG, 'base64');
}

/** setInputFiles / multipart 用のファイルペイロード。 */
export function pngUpload(name = 'sample.png') {
  return { name, mimeType: 'image/png', buffer: pngBuffer() };
}
