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

/** PNG チャンクの CRC32（Node のバージョン差を避けるため自前で持つ）。 */
function crc32(buf: Buffer): number {
  let crc = 0xffffffff;
  for (const byte of buf) {
    crc ^= byte;
    for (let i = 0; i < 8; i++) {
      crc = crc & 1 ? (crc >>> 1) ^ 0xedb88320 : crc >>> 1;
    }
  }
  return (crc ^ 0xffffffff) >>> 0;
}

/** type + data を PNG のチャンク（長さ + 型 + データ + CRC）に組み立てる。 */
function pngChunk(type: string, data: Buffer): Buffer {
  const length = Buffer.alloc(4);
  length.writeUInt32BE(data.length);
  const typeAndData = Buffer.concat([Buffer.from(type, 'ascii'), data]);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(typeAndData));
  return Buffer.concat([length, typeAndData, crc]);
}

/**
 * 指定バイト数ちょうどの「有効な PNG」を作る。
 *
 * 1x1 PNG の IEND 直前に tEXt チャンク（コメント）を挿し込んで嵩を増やす。
 * ランダムなバイト列で埋めると getimagesize が通らず、Laravel の image ルールで
 * 落ちてしまい「サイズ上限」の検証にならないため、画像として妥当なまま太らせる。
 *
 * @param bytes 生成するファイルサイズ（IHDR/IDAT/IEND + tEXt の最小サイズ以上）
 */
export function pngOfSize(bytes: number): Buffer {
  const base = pngBuffer();
  const iendAt = base.length - 12; // IEND チャンク（長さ4 + 型4 + CRC4）
  const head = base.subarray(0, iendAt);
  const iend = base.subarray(iendAt);

  const keyword = Buffer.from('Comment\0', 'ascii');
  const overhead = head.length + iend.length + 12 + keyword.length; // 12 = tEXt の長さ+型+CRC
  const padding = bytes - overhead;
  if (padding < 0) {
    throw new Error(`pngOfSize: ${bytes} バイトは小さすぎます（最小 ${overhead}）`);
  }

  const text = pngChunk('tEXt', Buffer.concat([keyword, Buffer.alloc(padding, 0x61)]));
  return Buffer.concat([head, text, iend]);
}

/** 指定サイズの PNG を multipart 用ペイロードにする。 */
export function pngUploadOfSize(bytes: number, name = 'sample.png') {
  return { name, mimeType: 'image/png', buffer: pngOfSize(bytes) };
}
