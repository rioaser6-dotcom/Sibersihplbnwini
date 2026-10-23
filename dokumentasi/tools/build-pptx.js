/**
 * Membangun presentasi Panduan Penggunaan SIBERSIH PLBN Wini (16:9).
 *
 * Cara pakai (dari folder ini):
 *   npm install
 *   node build-pptx.js
 *
 * Sumber gambar: ../screenshots/ (tangkapan layar asli aplikasi).
 * Keluaran: ../Presentasi_Panduan_Sistem.pptx
 */
const fs = require("fs");
const path = require("path");
const PptxGenJS = require("pptxgenjs");

const DOC_DIR = path.resolve(__dirname, "..");
const SHOT_DIR = path.join(DOC_DIR, "screenshots");
const OUT_PATH = path.join(DOC_DIR, "Presentasi_Panduan_Sistem.pptx");
const LOGO = path.join(DOC_DIR, "assets/logo-bnpp.png");

// ---------------------------------------------------------------------------
// Palet dan ukuran
// ---------------------------------------------------------------------------
const C = {
  navy: "0B1F3A",
  navy2: "123F74",
  blue: "1D6FE8",
  blueDark: "1E40AF",
  bluePale: "E8F0FE",
  sky: "7FB2EE",
  ink: "0F172A",
  slate: "475569",
  muted: "64748B",
  line: "DCE5F1",
  pale: "F5F8FD",
  white: "FFFFFF",
  green: "15803D",
  greenBg: "E8F7EE",
  red: "B91C1C",
  redBg: "FEECEC",
  amber: "B45309",
  amberBg: "FEF3C7",
  purple: "6D4AFF",
  purpleBg: "EFEBFF",
};
const FONT = "Segoe UI";
const W = 13.333;
const H = 7.5;
const MX = 0.6;

const pptx = new PptxGenJS();
pptx.layout = "LAYOUT_WIDE";
pptx.author = "SIBERSIH PLBN Wini";
pptx.company = "Badan Nasional Pengelola Perbatasan - PLBN Wini";
pptx.subject = "Panduan Penggunaan Sistem Informasi Pengajuan dan Distribusi Barang Kebersihan";
pptx.title = "Presentasi Panduan Penggunaan SIBERSIH PLBN Wini";
pptx.lang = "id-ID";
pptx.theme = { headFontFace: FONT, bodyFontFace: FONT, lang: "id-ID" };
pptx.defineSlideMaster({
  title: "KONTEN",
  background: { color: C.white },
});

// ---------------------------------------------------------------------------
// Utilitas gambar dan teks
// ---------------------------------------------------------------------------
function imageSize(file) {
  const b = fs.readFileSync(file);
  if (b[0] === 0x89 && b[1] === 0x50) {
    return { w: b.readUInt32BE(16), h: b.readUInt32BE(20) };
  }
  let i = 2;
  while (i < b.length) {
    if (b[i] !== 0xff) { i++; continue; }
    const marker = b[i + 1];
    if (marker >= 0xc0 && marker <= 0xcf && ![0xc4, 0xc8, 0xcc].includes(marker)) {
      return { w: b.readUInt16BE(i + 7), h: b.readUInt16BE(i + 5) };
    }
    if (marker === 0xd8 || marker === 0xd9 || (marker >= 0xd0 && marker <= 0xd7)) { i += 2; continue; }
    const len = b.readUInt16BE(i + 2);
    i += 2 + len;
  }
  throw new Error("Ukuran gambar tidak terbaca: " + file);
}

function txt(slide, text, x, y, w, h, opts = {}) {
  slide.addText(text, {
    x, y, w, h,
    fontFace: FONT,
    fontSize: 12,
    color: C.ink,
    margin: 0,
    valign: "top",
    fit: "shrink",
    ...opts,
  });
}

function box(slide, x, y, w, h, opts = {}) {
  slide.addShape(pptx.ShapeType.roundRect, {
    x, y, w, h,
    fill: { color: opts.fill || C.white },
    line: { color: opts.line || C.line, width: opts.lineWidth ?? 0.8 },
    rectRadius: opts.radius ?? 0.07,
    shadow: opts.shadow === false ? undefined : {
      type: "outer", color: "0B1F3A", opacity: 0.10, blur: 8, offset: 2, angle: 90,
    },
  });
}

function shot(slide, file, bx, by, bw, bh, opts = {}) {
  const filePath = path.join(SHOT_DIR, file);
  const dim = imageSize(filePath);
  const scale = Math.min(bw / dim.w, bh / dim.h);
  const iw = dim.w * scale;
  const ih = dim.h * scale;
  const ix = bx + (bw - iw) / 2;
  const iy = by + (bh - ih) / 2;
  if (opts.frame !== false) {
    slide.addShape(pptx.ShapeType.roundRect, {
      x: ix - 0.055, y: iy - 0.055, w: iw + 0.11, h: ih + 0.11,
      fill: { color: C.white },
      line: { color: "D5DFEC", width: 0.9 },
      rectRadius: 0.03,
      shadow: { type: "outer", color: "0B1F3A", opacity: 0.16, blur: 10, offset: 3, angle: 90 },
    });
  }
  slide.addImage({ path: filePath, x: ix, y: iy, w: iw, h: ih, altText: "Tangkapan layar aplikasi: " + file });
  return { x: ix, y: iy, w: iw, h: ih };
}

function mark(slide, rect, rx, ry, num, color = C.blue) {
  const cx = rect.x + rx * rect.w;
  const cy = rect.y + ry * rect.h;
  const d = 0.30;
  slide.addShape(pptx.ShapeType.ellipse, {
    x: cx - d / 2, y: cy - d / 2, w: d, h: d,
    fill: { color },
    line: { color: C.white, width: 1.4 },
    shadow: { type: "outer", color: "000000", opacity: 0.25, blur: 3, offset: 1, angle: 90 },
  });
  slide.addText(String(num), {
    x: cx - d / 2, y: cy - d / 2, w: d, h: d,
    fontSize: 10.5, bold: true, color: C.white,
    align: "center", valign: "middle", margin: 0, fontFace: FONT,
  });
}

function chip(slide, text, x, y, w, h, fill, color, opts = {}) {
  slide.addShape(pptx.ShapeType.roundRect, {
    x, y, w, h,
    fill: { color: fill },
    line: { color: opts.line || fill, width: 0.6 },
    rectRadius: 0.5,
  });
  txt(slide, text, x, y, w, h, {
    fontSize: opts.fontSize || 9.5,
    bold: true,
    color,
    align: "center",
    valign: "middle",
    margin: 0,
  });
}

function legendItem(slide, x, y, num, title, body, w = 3.55, color = C.blue) {
  slide.addShape(pptx.ShapeType.ellipse, {
    x, y: y + 0.02, w: 0.30, h: 0.30,
    fill: { color }, line: { color, width: 0.5 },
  });
  slide.addText(String(num), {
    x, y: y + 0.02, w: 0.30, h: 0.30,
    fontSize: 10, bold: true, color: C.white, align: "center", valign: "middle", margin: 0,
  });
  txt(slide, title, x + 0.43, y - 0.02, w - 0.43, 0.30, {
    fontSize: 11.5, bold: true, color: C.ink,
  });
  txt(slide, body, x + 0.43, y + 0.26, w - 0.43, 0.62, {
    fontSize: 9.3, color: C.slate, lineSpacingMultiple: 1.02,
  });
}

function base(slide, opts = {}) {
  if (opts.background !== false) {
    slide.background = { color: opts.background || C.white };
  }
  // Penanda kecil dan nama sistem
  slide.addShape(pptx.ShapeType.rect, {
    x: MX, y: 0.43, w: 0.075, h: 0.20,
    fill: { color: C.blue }, line: { color: C.blue, width: 0 },
  });
  txt(slide, "SIBERSIH PLBN WINI", MX + 0.18, 0.39, 3.5, 0.26, {
    fontSize: 8.5, bold: true, color: C.blue, charSpacing: 1.8,
  });
  if (opts.title) {
    txt(slide, opts.title, MX, 0.61, 11.6, 0.55, {
      fontSize: opts.titleSize || 25,
      bold: true,
      color: C.navy,
      valign: "middle",
    });
  }
  if (opts.subtitle) {
    txt(slide, opts.subtitle, MX, 1.15, 11.9, 0.40, {
      fontSize: 11.5,
      color: C.muted,
    });
  }
  slide.addShape(pptx.ShapeType.rect, {
    x: MX, y: 1.62, w: W - 2 * MX, h: 0.014,
    fill: { color: C.line }, line: { color: C.line, width: 0 },
  });
  // Footer
  slide.addShape(pptx.ShapeType.rect, {
    x: MX, y: 7.10, w: W - 2 * MX, h: 0.01,
    fill: { color: C.line }, line: { color: C.line, width: 0 },
  });
  txt(slide, "SIBERSIH PLBN Wini  |  Panduan Penggunaan Sistem", MX, 7.15, 8.5, 0.22, {
    fontSize: 8, color: C.muted,
  });
  txt(slide, String(opts.page || ""), W - MX - 0.5, 7.15, 0.5, 0.22, {
    fontSize: 8, color: C.muted, align: "right",
  });
}

function sourceNote(slide, text, x = MX, y = 6.83, w = 12.1) {
  txt(slide, text, x, y, w, 0.22, {
    fontSize: 7.8, italic: true, color: C.muted,
  });
}

function notes(slide, text) {
  slide.addNotes(text);
}

// ---------------------------------------------------------------------------
// 1. Sampul
// ---------------------------------------------------------------------------
{
  const s = pptx.addSlide();
  s.background = { color: C.navy };

  // Aksen geometris sederhana, tanpa animasi.
  s.addShape(pptx.ShapeType.rect, {
    x: 0, y: 0, w: 0.14, h: H, fill: { color: C.blue }, line: { color: C.blue, width: 0 },
  });
  s.addShape(pptx.ShapeType.ellipse, {
    x: 9.2, y: -2.1, w: 6.4, h: 6.4,
    fill: { color: C.navy2, transparency: 55 }, line: { color: C.navy2, width: 0 },
  });
  s.addShape(pptx.ShapeType.ellipse, {
    x: 11.0, y: 5.4, w: 3.1, h: 3.1,
    fill: { color: C.blue, transparency: 78 }, line: { color: C.blue, width: 0 },
  });

  s.addShape(pptx.ShapeType.ellipse, {
    x: 0.78, y: 0.62, w: 0.86, h: 0.86,
    fill: { color: C.white }, line: { color: C.white, width: 0 },
  });
  s.addImage({ path: LOGO, x: 0.84, y: 0.68, w: 0.74, h: 0.74 });
  txt(s, "BADAN NASIONAL PENGELOLA PERBATASAN", 1.82, 0.66, 6.4, 0.25, {
    fontSize: 9, bold: true, color: C.white, charSpacing: 0.7,
  });
  txt(s, "POS LINTAS BATAS NEGARA WINI", 1.82, 0.93, 6.4, 0.24, {
    fontSize: 8.6, color: C.sky, charSpacing: 1.2,
  });

  txt(s, "PANDUAN PENGGUNAAN SISTEM", 0.8, 1.90, 6.0, 0.30, {
    fontSize: 10, bold: true, color: C.sky, charSpacing: 2.4,
  });
  txt(s, "SIBERSIH", 0.8, 2.25, 6.6, 0.90, {
    fontSize: 46, bold: true, color: C.white,
  });
  txt(s, "PLBN Wini", 0.8, 3.02, 6.6, 0.60, {
    fontSize: 28, bold: true, color: C.sky,
  });
  s.addShape(pptx.ShapeType.rect, {
    x: 0.82, y: 3.78, w: 1.35, h: 0.055,
    fill: { color: C.blue }, line: { color: C.blue, width: 0 },
  });
  txt(s, "Sistem Informasi Pengajuan dan Distribusi\nBarang Kebersihan", 0.8, 3.98, 6.5, 0.85, {
    fontSize: 15, color: "DCE9F8", lineSpacingMultiple: 1.12,
  });
  txt(s, "Panduan langkah demi langkah untuk Admin dan Pengguna, disusun dari aplikasi yang berjalan.", 0.8, 4.95, 5.9, 0.62, {
    fontSize: 9.8, color: "9FB8D6", lineSpacingMultiple: 1.15,
  });

  shot(s, "05_admin_dashboard.jpg", 7.35, 1.95, 5.35, 3.35, { frame: true });
  txt(s, "Tampilan Dashboard Admin", 7.35, 5.42, 5.35, 0.24, {
    fontSize: 8.5, italic: true, color: "AFC4DD", align: "center",
  });

  txt(s, "Edisi 2026", 0.8, 6.62, 3.0, 0.26, {
    fontSize: 9.5, bold: true, color: C.sky, charSpacing: 1.2,
  });
  txt(s, "Dokumen internal  •  PLBN Wini, Insana Utara, TTU, NTT", 3.4, 6.62, 5.4, 0.26, {
    fontSize: 8.4, color: "8FA9C7", align: "right",
  });
  notes(s, "Presentasi ini merangkum panduan penggunaan SIBERSIH PLBN Wini. Seluruh gambar diambil dari aplikasi yang berjalan, bukan mockup. Data pribadi pada tangkapan layar telah disamarkan.");
}

// ---------------------------------------------------------------------------
// 2. Tentang sistem
// ---------------------------------------------------------------------------
{
  const s = pptx.addSlide({ masterName: "KONTEN" });
  base(s, {
    title: "Satu alur untuk pengajuan, distribusi, dan bukti",
    subtitle: "SIBERSIH menggantikan pencatatan manual dengan riwayat yang tercatat dan stok yang terpantau.",
    page: 2,
  });

  const items = [
    ["01", "Pengajuan terpusat", "Pengguna memilih barang dan jumlah, lalu mengirim satu pengajuan."],
    ["02", "Persetujuan berbasis stok", "Admin memeriksa pengajuan. Stok berkurang saat pengajuan disetujui."],
    ["03", "Distribusi dan bukti", "Barang dicatat keluar, lalu Pengguna mengunggah bukti penerimaan."],
    ["04", "Laporan resmi", "Pengajuan yang selesai dapat dicetak dalam format laporan A4 berkop."],
  ];
  let y = 1.86;
  for (const [n, t, d] of items) {
    s.addShape(pptx.ShapeType.roundRect, {
      x: MX, y, w: 0.48, h: 0.48,
      fill: { color: C.bluePale }, line: { color: C.bluePale, width: 0 },
      rectRadius: 0.08,
    });
    txt(s, n, MX, y, 0.48, 0.48, {
      fontSize: 11, bold: true, color: C.blue, align: "center", valign: "middle",
    });
    txt(s, t, MX + 0.63, y - 0.03, 4.6, 0.28, { fontSize: 12.5, bold: true, color: C.navy });
    txt(s, d, MX + 0.63, y + 0.25, 4.9, 0.52, { fontSize: 9.6, color: C.slate, lineSpacingMultiple: 1.05 });
    y += 1.16;
  }

  const r = shot(s, "01_landing.jpg", 6.55, 1.85, 6.15, 4.55);
  mark(s, r, 0.79, 0.20, 1);
  txt(s, "Halaman utama: tombol Login (1) menuju halaman masuk dan pemulihan akun.", 6.55, 6.55, 6.15, 0.40, {
    fontSize: 8.5, italic: true, color: C.muted, align: "center", lineSpacingMultiple: 1.05,
  });
  notes(s, "SIBERSIH adalah aplikasi web internal PLBN Wini untuk barang kebersihan. Empat fungsi utama: pengajuan, persetujuan, distribusi dengan bukti, dan laporan.");
}

// ---------------------------------------------------------------------------
// 3. Peran
// ---------------------------------------------------------------------------
{
  const s = pptx.addSlide({ masterName: "KONTEN" });
  base(s, {
    title: "Dua peran aktif, tanggung jawab yang jelas",
    subtitle: "Hak akses ditentukan oleh jenis akun. Menu yang tampil menyesuaikan peran.",
    page: 3,
  });

  const roles = [
    {
      x: MX, color: C.blue, bg: C.bluePale, label: "ADMIN", title: "Pengelola perlengkapan",
      items: [
        "Mengelola data barang dan stok",
        "Menyetujui atau menolak pengajuan",
        "Mencatat distribusi dan mengirim pesan",
        "Memverifikasi bukti penerimaan",
        "Mencetak laporan dan mengelola anggota",
      ],
    },
    {
      x: 6.83, color: C.green, bg: C.greenBg, label: "PENGGUNA", title: "Pegawai PLBN Wini",
      items: [
        "Melihat katalog dan sisa stok",
        "Membuat serta mengirim pengajuan",
        "Memantau status di Riwayat Pengajuan",
        "Membaca pesan dari Admin",
        "Mengunggah bukti penerimaan barang",
      ],
    },
  ];

  for (const r of roles) {
    box(s, r.x, 1.86, 5.90, 4.55, { fill: C.white });
    s.addShape(pptx.ShapeType.roundRect, {
      x: r.x + 0.28, y: 2.08, w: 1.30, h: 0.32,
      fill: { color: r.bg }, line: { color: r.bg, width: 0 },
      rectRadius: 0.5,
    });
    txt(s, r.label, r.x + 0.28, 2.08, 1.30, 0.32, {
      fontSize: 8.5, bold: true, color: r.color, align: "center", valign: "middle", charSpacing: 1,
    });
    txt(s, r.title, r.x + 0.28, 2.52, 5.3, 0.40, { fontSize: 17, bold: true, color: C.navy });
    s.addShape(pptx.ShapeType.rect, {
      x: r.x + 0.28, y: 3.00, w: 5.34, h: 0.012,
      fill: { color: C.line }, line: { color: C.line, width: 0 },
    });
    let iy = 3.16;
    for (const it of r.items) {
      s.addShape(pptx.ShapeType.ellipse, {
        x: r.x + 0.30, y: iy + 0.075, w: 0.11, h: 0.11,
        fill: { color: r.color }, line: { color: r.color, width: 0 },
      });
      txt(s, it, r.x + 0.53, iy, 5.05, 0.30, { fontSize: 10.2, color: C.slate });
      iy += 0.53;
    }
  }

  s.addShape(pptx.ShapeType.roundRect, {
    x: MX, y: 6.55, w: 12.13, h: 0.46,
    fill: { color: C.amberBg }, line: { color: "F2D28B", width: 0.7 },
    rectRadius: 0.06,
  });
  txt(s, "Catatan verifikasi: peran vendor dikenali struktur basis data, tetapi belum memiliki halaman/alur kerja dan login dengan peran ini ditolak.", MX + 0.18, 6.55, 11.8, 0.46, {
    fontSize: 8.7, color: C.amber, valign: "middle",
  });
  notes(s, "Hanya ada dua peran yang benar-benar aktif: Admin dan Pengguna. Peran vendor ada di enum database tetapi tidak dapat login dan tidak punya antarmuka.");
}

// ---------------------------------------------------------------------------
// 4. Alur kerja
// ---------------------------------------------------------------------------
{
  const s = pptx.addSlide({ masterName: "KONTEN" });
  base(s, {
    title: "Alur kerja utama dalam lima tahap",
    subtitle: "Setiap tindakan mengubah status. Stok berkurang pada tahap persetujuan, bukan saat distribusi.",
    page: 4,
  });

  const steps = [
    { t: "Ajukan", who: "Pengguna", st: "Menunggu", sc: C.amber, sb: C.amberBg, d: "Pilih barang,\nisi jumlah,\nkirim." },
    { t: "Periksa", who: "Admin", st: "Disetujui / Ditolak", sc: C.blue, sb: C.bluePale, d: "Periksa stok,\nsetujui atau\ntolak." },
    { t: "Distribusi", who: "Admin", st: "Didistribusi", sc: C.purple, sb: C.purpleBg, d: "Serahkan barang,\ncatat distribusi." },
    { t: "Bukti", who: "Pengguna", st: "Menunggu verifikasi", sc: C.amber, sb: C.amberBg, d: "Unggah foto\nbukti terima." },
    { t: "Verifikasi", who: "Admin", st: "Selesai", sc: C.green, sb: C.greenBg, d: "Periksa bukti,\nnyatakan selesai." },
  ];
  const bw = 2.20;
  const gap = 0.28;
  const startX = MX + 0.02;
  steps.forEach((st, i) => {
    const x = startX + i * (bw + gap);
    box(s, x, 2.25, bw, 1.95, { fill: C.white });
    s.addShape(pptx.ShapeType.ellipse, {
      x: x + 0.16, y: 2.40, w: 0.38, h: 0.38,
      fill: { color: st.sc }, line: { color: st.sc, width: 0 },
    });
    txt(s, String(i + 1), x + 0.16, 2.40, 0.38, 0.38, {
      fontSize: 12, bold: true, color: C.white, align: "center", valign: "middle",
    });
    txt(s, st.t, x + 0.63, 2.40, 1.45, 0.22, { fontSize: 12.5, bold: true, color: C.navy });
    txt(s, st.who, x + 0.63, 2.64, 1.45, 0.18, { fontSize: 8, bold: true, color: st.sc, charSpacing: 0.8 });
    chip(s, st.st, x + 0.16, 2.96, bw - 0.32, 0.28, st.sb, st.sc, { fontSize: 8.2 });
    txt(s, st.d, x + 0.16, 3.33, bw - 0.32, 0.72, { fontSize: 8.8, color: C.slate, lineSpacingMultiple: 1.02 });

    if (i < steps.length - 1) {
      s.addShape(pptx.ShapeType.rightArrow, {
        x: x + bw + 0.05, y: 3.06, w: 0.18, h: 0.22,
        fill: { color: "B6C6DC" }, line: { color: "B6C6DC", width: 0 },
      });
    }
  });

  s.addShape(pptx.ShapeType.roundRect, {
    x: MX, y: 4.55, w: 5.92, h: 1.85,
    fill: { color: C.bluePale }, line: { color: "C7D8F5", width: 0.8 },
    rectRadius: 0.07,
  });
  txt(s, "Dua titik kendali stok", MX + 0.25, 4.73, 5.4, 0.28, { fontSize: 11.5, bold: true, color: C.blueDark });
  txt(s, "1.  Stok diverifikasi dan dikurangi saat Admin menyetujui.\n2.  Stok tidak berubah saat pengajuan masih Menunggu atau saat distribusi dicatat.", MX + 0.25, 5.06, 5.45, 1.10, {
    fontSize: 9.4, color: C.slate, lineSpacingMultiple: 1.18,
  });

  s.addShape(pptx.ShapeType.roundRect, {
    x: 6.81, y: 4.55, w: 5.92, h: 1.85,
    fill: { color: C.pale }, line: { color: C.line, width: 0.8 },
    rectRadius: 0.07,
  });
  txt(s, "Arti status akhir", 7.06, 4.73, 5.4, 0.28, { fontSize: 11.5, bold: true, color: C.navy });
  txt(s, "Selesai  = bukti sudah diverifikasi dan masuk laporan.\nDitolak  = pengajuan tidak diproses; stok tidak berubah.\nPerlu perbaikan  = bukti belum memenuhi syarat, Pengguna mengunggah ulang.", 7.06, 5.06, 5.45, 1.10, {
    fontSize: 9.4, color: C.slate, lineSpacingMultiple: 1.14,
  });
  notes(s, "Lima tahap: pengajuan, pemeriksaan, distribusi, unggah bukti, verifikasi. Stok hanya berkurang pada persetujuan. Status akhir Selesai hanya tercapai setelah bukti diverifikasi.");
}

// ---------------------------------------------------------------------------
// 5. Status dan bukti
// ---------------------------------------------------------------------------
{
  const s = pptx.addSlide({ masterName: "KONTEN" });
  base(s, {
    title: "Status pengajuan dan status bukti",
    subtitle: "Status pengajuan berjalan satu arah. Status bukti memiliki cabang verifikasi dan perbaikan.",
    page: 5,
  });

  txt(s, "STATUS PENGAJUAN", MX, 1.84, 5.0, 0.22, {
    fontSize: 9, bold: true, color: C.muted, charSpacing: 1.4,
  });
  const ch = [
    { t: "Menunggu", c: C.amber, bg: C.amberBg, d: "Pengajuan baru" },
    { t: "Disetujui", c: C.blue, bg: C.bluePale, d: "Stok berkurang" },
    { t: "Didistribusi", c: C.purple, bg: C.purpleBg, d: "Barang dicatat keluar" },
    { t: "Selesai", c: C.green, bg: C.greenBg, d: "Masuk laporan" },
  ];
  const cw = 2.45;
  const cg = 0.45;
  ch.forEach((x, i) => {
    const bx = MX + 0.2 + i * (cw + cg);
    chip(s, x.t, bx, 2.16, cw, 0.50, x.bg, x.c, { fontSize: 10.5 });
    txt(s, x.d, bx, 2.72, cw, 0.22, { fontSize: 8.2, color: C.muted, align: "center" });
    if (i < ch.length - 1) {
      s.addShape(pptx.ShapeType.rightArrow, {
        x: bx + cw + 0.10, y: 2.31, w: 0.25, h: 0.20,
        fill: { color: "B6C6DC" }, line: { color: "B6C6DC", width: 0 },
      });
    }
  });
  // Cabang ditolak
  chip(s, "Ditolak", MX + 0.2, 3.18, 1.30, 0.36, C.redBg, C.red, { fontSize: 9.2 });
  txt(s, "cabang dari Menunggu; stok tidak berubah", MX + 1.62, 3.25, 3.8, 0.22, {
    fontSize: 8.2, color: C.muted,
  });
  s.addShape(pptx.ShapeType.line, {
    x: MX + 0.85, y: 2.70, w: 0, h: 0.46,
    line: { color: "D9A3A3", width: 0.9, dashType: "dash" },
  });

  s.addShape(pptx.ShapeType.rect, {
    x: MX, y: 3.78, w: 12.13, h: 0.012,
    fill: { color: C.line }, line: { color: C.line, width: 0 },
  });
  txt(s, "STATUS BUKTI PENERIMAAN", MX, 3.95, 6.0, 0.22, {
    fontSize: 9, bold: true, color: C.muted, charSpacing: 1.4,
  });
  chip(s, "Belum ada", MX + 0.25, 4.24, 2.25, 0.42, "EEF2F7", C.muted, { fontSize: 9.2 });
  txt(s, "belum diunggah", MX + 0.25, 4.72, 2.25, 0.20, { fontSize: 8.0, color: C.muted, align: "center" });
  s.addShape(pptx.ShapeType.rightArrow, {
    x: MX + 2.62, y: 4.37, w: 0.24, h: 0.16,
    fill: { color: "B6C6DC" }, line: { color: "B6C6DC", width: 0 },
  });
  chip(s, "Menunggu verifikasi", MX + 3.00, 4.24, 2.55, 0.42, C.amberBg, C.amber, { fontSize: 9.2 });
  txt(s, "sudah diunggah", MX + 3.00, 4.72, 2.55, 0.20, { fontSize: 8.0, color: C.muted, align: "center" });

  // Cabang keputusan: satu input, dua kemungkinan hasil.
  const branchX = MX + 5.92;
  s.addShape(pptx.ShapeType.line, {
    x: MX + 5.55, y: 4.45, w: 0.37, h: 0,
    line: { color: "9FB3CC", width: 1.0 },
  });
  s.addShape(pptx.ShapeType.line, {
    x: branchX, y: 4.02, w: 0, h: 0.86,
    line: { color: "9FB3CC", width: 1.0 },
  });
  s.addShape(pptx.ShapeType.line, {
    x: branchX, y: 4.02, w: 0.95, h: 0,
    line: { color: "9FB3CC", width: 1.0, endArrowType: "triangle" },
  });
  s.addShape(pptx.ShapeType.line, {
    x: branchX, y: 4.88, w: 0.95, h: 0,
    line: { color: "9FB3CC", width: 1.0, endArrowType: "triangle" },
  });
  chip(s, "Terverifikasi", MX + 6.95, 3.83, 2.35, 0.40, C.greenBg, C.green, { fontSize: 9.2 });
  txt(s, "bukti diterima, pengajuan Selesai", MX + 6.95, 4.26, 2.45, 0.20, { fontSize: 7.8, color: C.muted, align: "center" });
  chip(s, "Perlu perbaikan", MX + 6.95, 4.67, 2.35, 0.40, C.redBg, C.red, { fontSize: 9.2 });
  txt(s, "bukti kurang jelas, unggah ulang", MX + 6.95, 5.10, 2.55, 0.20, { fontSize: 7.8, color: C.muted, align: "center" });

  s.addShape(pptx.ShapeType.roundRect, {
    x: MX, y: 5.35, w: 5.92, h: 1.12,
    fill: { color: C.pale }, line: { color: C.line, width: 0.8 },
    rectRadius: 0.06,
  });
  txt(s, "Pesan tidak mengubah status", MX + 0.20, 5.48, 5.5, 0.24, { fontSize: 10.5, bold: true, color: C.navy });
  txt(s, "Pesan distribusi hanya pemberitahuan satu arah. Perubahan status tetap melalui persetujuan, distribusi, dan verifikasi Admin.", MX + 0.20, 5.75, 5.5, 0.58, {
    fontSize: 8.8, color: C.slate, lineSpacingMultiple: 1.08,
  });
  s.addShape(pptx.ShapeType.roundRect, {
    x: 6.81, y: 5.35, w: 5.92, h: 1.12,
    fill: { color: C.pale }, line: { color: C.line, width: 0.8 },
    rectRadius: 0.06,
  });
  txt(s, "Aturan penghapusan", 7.01, 5.48, 5.5, 0.24, { fontSize: 10.5, bold: true, color: C.navy });
  txt(s, "Pengguna hanya dapat menghapus pengajuan yang masih Menunggu. Setelah diproses Admin, pengajuan tidak dapat dihapus.", 7.01, 5.75, 5.5, 0.58, {
    fontSize: 8.8, color: C.slate, lineSpacingMultiple: 1.08,
  });
  notes(s, "Status pengajuan: Menunggu, Disetujui atau Ditolak, Didistribusi, lalu Selesai. Status bukti: belum ada, menunggu verifikasi, terverifikasi, atau perlu perbaikan. Pesan tidak mengubah status.");
}

// ---------------------------------------------------------------------------
// 6. Login
// ---------------------------------------------------------------------------
{
  const s = pptx.addSlide({ masterName: "KONTEN" });
  base(s, {
    title: "Masuk ke sistem dengan aman",
    subtitle: "Gunakan akun yang diberikan Admin. Halaman login juga menyediakan pemulihan berbasis NIK.",
    page: 6,
  });

  const r = shot(s, "02_login.jpg", 6.62, 1.82, 6.10, 4.85);
  const steps = [
    ["Masukkan username", "Ketik username yang diberikan Admin, tanpa spasi tambahan."],
    ["Masukkan password", "Perhatikan Caps Lock. Ikon mata membantu memeriksa isi kolom."],
    ["Lupa akses?", "Gunakan tautan Lupa Username & Password? lalu verifikasi NIK."],
    ["Klik Login ke Sistem", "Jika berhasil, Anda diarahkan ke dashboard sesuai peran."],
  ];
  let y = 1.86;
  steps.forEach(([t, d], i) => {
    s.addShape(pptx.ShapeType.ellipse, {
      x: MX, y, w: 0.34, h: 0.34,
      fill: { color: C.blue }, line: { color: C.blue, width: 0 },
    });
    txt(s, String(i + 1), MX, y, 0.34, 0.34, {
      fontSize: 10.5, bold: true, color: C.white, align: "center", valign: "middle",
    });
    txt(s, t, MX + 0.48, y - 0.03, 5.2, 0.26, { fontSize: 11.5, bold: true, color: C.navy });
    txt(s, d, MX + 0.48, y + 0.23, 5.2, 0.50, { fontSize: 9.3, color: C.slate, lineSpacingMultiple: 1.05 });
    y += 1.08;
  });

  s.addShape(pptx.ShapeType.roundRect, {
    x: MX, y: 6.15, w: 5.75, h: 0.62,
    fill: { color: C.bluePale }, line: { color: "C7D8F5", width: 0.7 },
    rectRadius: 0.06,
  });
  txt(s, "Keamanan: Admin tidak pernah meminta password melalui telepon, pesan singkat, atau pesan aplikasi.", MX + 0.15, 6.15, 5.45, 0.62, {
    fontSize: 8.6, color: C.blueDark, valign: "middle",
  });

  mark(s, r, 0.39, 0.46, 1);
  mark(s, r, 0.39, 0.54, 2);
  mark(s, r, 0.56, 0.59, 3);
  mark(s, r, 0.50, 0.65, 4);
  sourceNote(s, "Tangkapan layar: halaman login aplikasi. Kolom password tidak diisi.", 6.62, 6.78, 6.1);
  notes(s, "Login memakai username dan password. Pemulihan akun memakai NIK. Jangan pernah membagikan password; Admin tidak memintanya.");
}

// ---------------------------------------------------------------------------
// 7. Dashboard Admin
// ---------------------------------------------------------------------------
{
  const s = pptx.addSlide({ masterName: "KONTEN" });
  base(s, {
    title: "Dashboard Admin: ringkasan dalam satu layar",
    subtitle: "Kartu angka memberi gambaran cepat; tabel di bawah menampilkan pengajuan terbaru.",
    page: 7,
  });

  const r = shot(s, "05_admin_dashboard.jpg", 4.55, 1.80, 8.18, 5.02);
  legendItem(s, MX, 1.90, 1, "Navigasi peran", "Menu Admin: Barang, Pengajuan, Distribusi, Laporan, dan Anggota.", 3.55);
  legendItem(s, MX, 3.05, 2, "Ringkasan status", "Total pengajuan, Menunggu, Disetujui, Ditolak, Didistribusi, dan Selesai.", 3.55);
  legendItem(s, MX, 4.55, 3, "Pengajuan terbaru", "Tabel ringkas dengan tombol Detail untuk membuka data lengkap.", 3.55);
  legendItem(s, MX, 5.75, 4, "Persentase penyelesaian", "Perbandingan pengajuan selesai terhadap seluruh pengajuan.", 3.55);

  mark(s, r, 0.163, 0.29, 1);
  mark(s, r, 0.53, 0.222, 2);
  mark(s, r, 0.55, 0.455, 3);
  mark(s, r, 0.305, 0.40, 4);

  sourceNote(s, "Tangkapan layar: Dashboard Admin dengan data uji. Angka dapat berubah sesuai data aktual.", 4.55, 6.87, 8.1);
  notes(s, "Dashboard Admin merangkum jumlah pengajuan per status dan menampilkan tabel pengajuan terbaru. Gunakan tombol Detail untuk memeriksa satu pengajuan.");
}

// ---------------------------------------------------------------------------
// 8. Kelola Barang
// ---------------------------------------------------------------------------
{
  const s = pptx.addSlide({ masterName: "KONTEN" });
  base(s, {
    title: "Kelola Barang: stok dan katalog",
    subtitle: "Tambah barang, cari data, perbarui stok, atau hapus barang yang tidak lagi dipakai.",
    page: 8,
  });

  const r = shot(s, "06_admin_barang.jpg", 4.85, 1.80, 7.88, 4.92);
  legendItem(s, MX, 1.90, 1, "Tambah Barang", "Membuka formulir nama, jenis, satuan, stok, dan gambar barang.", 3.9);
  legendItem(s, MX, 2.95, 2, "Cari dan filter", "Cari berdasarkan nama atau saring menurut jenis barang.", 3.9);
  legendItem(s, MX, 4.00, 3, "Edit dan Hapus", "Edit memperbarui data/stok; Hapus menolak barang yang punya riwayat.", 3.9);
  s.addShape(pptx.ShapeType.roundRect, {
    x: MX, y: 5.35, w: 3.95, h: 1.30,
    fill: { color: C.amberBg }, line: { color: "F2D28B", width: 0.7 },
    rectRadius: 0.06,
  });
  txt(s, "Ingat", MX + 0.18, 5.48, 3.6, 0.24, { fontSize: 10.5, bold: true, color: C.amber });
  txt(s, "Mengubah stok di sini tidak menghapus riwayat pengajuan. Stok juga berkurang otomatis saat pengajuan disetujui.", MX + 0.18, 5.75, 3.6, 0.80, {
    fontSize: 8.7, color: C.slate, lineSpacingMultiple: 1.08,
  });

  mark(s, r, 0.825, 0.065, 1);
  mark(s, r, 0.335, 0.37, 2);
  mark(s, r, 0.965, 0.50, 3);

  sourceNote(s, "Tangkapan layar: halaman Kelola Barang. Data dan jumlah stok adalah data uji.", 4.85, 6.82, 7.8);
  notes(s, "Kelola Barang dipakai untuk merawat katalog dan stok. Penghapusan barang dengan riwayat akan ditolak aplikasi. Stok berkurang saat persetujuan pengajuan.");
}

// ---------------------------------------------------------------------------
// 9. Kelola Pengajuan
// ---------------------------------------------------------------------------
{
  const s = pptx.addSlide({ masterName: "KONTEN" });
  base(s, {
    title: "Kelola Pengajuan: periksa sebelum memutuskan",
    subtitle: "Buka detail untuk melihat barang, jumlah, catatan, dan status bukti sebelum menyetujui.",
    page: 9,
  });

  const r = shot(s, "09_admin_pengajuan.jpg", 4.70, 1.80, 8.03, 5.02);
  legendItem(s, MX, 1.90, 1, "Daftar semua pengajuan", "Satu baris per pengajuan, lengkap dengan pengaju dan divisi.", 3.75);
  legendItem(s, MX, 3.02, 2, "Status berwarna", "Menunggu, Disetujui, Ditolak, Didistribusi, dan Selesai.", 3.75);
  legendItem(s, MX, 4.14, 3, "Tombol Detail", "Membuka rincian barang, catatan, status bukti, dan tindakan.", 3.75);

  s.addShape(pptx.ShapeType.roundRect, {
    x: MX, y: 5.42, w: 3.78, h: 1.22,
    fill: { color: C.bluePale }, line: { color: "C7D8F5", width: 0.7 },
    rectRadius: 0.06,
  });
  txt(s, "Keputusan Admin", MX + 0.17, 5.55, 3.45, 0.24, { fontSize: 10.3, bold: true, color: C.blueDark });
  txt(s, "Setujui atau Tolak hanya tersedia saat status Menunggu. Pada data uji semua pengajuan sudah diproses, sehingga tombol itu tidak tampak.", MX + 0.17, 5.81, 3.45, 0.75, {
    fontSize: 8.4, color: C.slate, lineSpacingMultiple: 1.07,
  });

  mark(s, r, 0.18, 0.405, 1);
  mark(s, r, 0.83, 0.405, 2);
  mark(s, r, 0.915, 0.405, 3);

  sourceNote(s, "Tangkapan layar: daftar pengajuan Admin. Nama dan data pribadi telah disamarkan.", 4.70, 6.87, 7.95);
  notes(s, "Halaman Kelola Pengajuan menampilkan seluruh pengajuan. Detail dipakai untuk memeriksa barang dan bukti. Tombol Setujui/Tolak hanya muncul untuk status Menunggu; tangkapan layar data uji tidak menampilkannya karena semua sudah diproses.");
}

// ---------------------------------------------------------------------------
// 10. Distribusi
// ---------------------------------------------------------------------------
{
  const s = pptx.addSlide({ masterName: "KONTEN" });
  base(s, {
    title: "Distribusi dan verifikasi bukti",
    subtitle: "Tiga panel memisahkan pekerjaan: siap didistribusikan, bukti menunggu verifikasi, dan distribusi berjalan.",
    page: 10,
  });

  const r = shot(s, "11_admin_distribusi.jpg", MX, 1.78, 5.95, 5.00);
  const items = [
    ["1", "Catat distribusi", "Pilih pengajuan Disetujui, isi tanggal dan catatan, lalu simpan. Status menjadi Didistribusi."],
    ["2", "Kirim pesan", "Gunakan Kirim Pesan untuk memberi tahu Pengguna. Pesan tidak mengubah status."],
    ["3", "Verifikasi bukti", "Periksa foto yang diunggah. Setujui menjadi Selesai atau minta perbaikan."],
  ];
  let y = 1.86;
  for (const [n, t, d] of items) {
    s.addShape(pptx.ShapeType.ellipse, {
      x: 6.85, y, w: 0.36, h: 0.36,
      fill: { color: C.blue }, line: { color: C.blue, width: 0 },
    });
    txt(s, n, 6.85, y, 0.36, 0.36, {
      fontSize: 11, bold: true, color: C.white, align: "center", valign: "middle",
    });
    txt(s, t, 7.35, y - 0.04, 4.7, 0.28, { fontSize: 12.5, bold: true, color: C.navy });
    txt(s, d, 7.35, y + 0.25, 4.85, 0.72, { fontSize: 9.3, color: C.slate, lineSpacingMultiple: 1.08 });
    y += 1.27;
  }
  s.addShape(pptx.ShapeType.roundRect, {
    x: 6.85, y: 5.72, w: 5.35, h: 0.92,
    fill: { color: C.greenBg }, line: { color: "BFE3CB", width: 0.7 },
    rectRadius: 0.06,
  });
  txt(s, "Bukti yang diterima", 7.02, 5.83, 5.0, 0.22, { fontSize: 10.3, bold: true, color: C.green });
  txt(s, "JPG, PNG, atau WEBP; maksimal 5 MB. Setelah diverifikasi, status pengajuan menjadi Selesai.", 7.02, 6.07, 4.95, 0.48, {
    fontSize: 8.5, color: C.slate, lineSpacingMultiple: 1.05,
  });

  mark(s, r, 0.95, 0.16, 1);
  mark(s, r, 0.95, 0.44, 2);
  mark(s, r, 0.95, 0.72, 3);

  sourceNote(s, "Tangkapan layar: halaman Distribusi dalam keadaan kosong karena tidak ada data pada tahap tersebut.", MX, 6.85, 5.95);
  notes(s, "Halaman Distribusi punya tiga panel. Catat distribusi untuk pengajuan Disetujui, kirim pesan bila perlu, lalu verifikasi bukti yang diunggah Pengguna. Pesan tidak mengubah status.");
}

// ---------------------------------------------------------------------------
// 11. Laporan
// ---------------------------------------------------------------------------
{
  const s = pptx.addSlide({ masterName: "KONTEN" });
  base(s, {
    title: "Laporan siap cetak",
    subtitle: "Laporan hanya memuat pengajuan Selesai dan mengikuti periode yang dipilih.",
    page: 11,
  });

  const r = shot(s, "13_admin_laporan_cetak.jpg", 5.08, 1.72, 7.65, 5.12);
  const lines = [
    ["Filter periode", "Pilih Per Bulan atau rentang tanggal, lalu klik Terapkan Filter."],
    ["Hanya status Selesai", "Pengajuan Ditolak atau yang masih berjalan tidak masuk laporan."],
    ["Cetak Laporan", "Membuka pratinjau A4 dengan kop resmi PLBN Wini."],
    ["Simpan sebagai PDF", "Pada jendela cetak browser, pilih Save as PDF untuk arsip digital."],
  ];
  let y = 1.86;
  for (const [t, d] of lines) {
    s.addShape(pptx.ShapeType.rect, {
      x: MX, y: y + 0.04, w: 0.06, h: 0.78,
      fill: { color: C.blue }, line: { color: C.blue, width: 0 },
    });
    txt(s, t, MX + 0.20, y, 4.1, 0.26, { fontSize: 11.3, bold: true, color: C.navy });
    txt(s, d, MX + 0.20, y + 0.26, 4.15, 0.55, { fontSize: 9.0, color: C.slate, lineSpacingMultiple: 1.06 });
    y += 1.10;
  }
  s.addShape(pptx.ShapeType.roundRect, {
    x: MX, y: 6.28, w: 4.35, h: 0.46,
    fill: { color: C.amberBg }, line: { color: "F2D28B", width: 0.7 },
    rectRadius: 0.06,
  });
  txt(s, "Tidak ada ekspor Excel/PDF langsung; gunakan cetak browser.", MX + 0.13, 6.28, 4.1, 0.46, {
    fontSize: 8.2, color: C.amber, valign: "middle",
  });

  sourceNote(s, "Tangkapan layar: pratinjau cetak laporan A4 dengan kop instansi.", 5.08, 6.85, 7.55);
  notes(s, "Laporan memakai filter periode. Hanya pengajuan berstatus Selesai yang tercetak. Pratinjau memakai kop resmi; PDF dibuat melalui Save as PDF di browser.");
}

// ---------------------------------------------------------------------------
// 12. Kelola Anggota
// ---------------------------------------------------------------------------
{
  const s = pptx.addSlide({ masterName: "KONTEN" });
  base(s, {
    title: "Kelola Anggota: akun dibuat oleh Admin",
    subtitle: "Tidak ada pendaftaran mandiri. Admin membuat akun Pengguna dan dapat mengubah atau menghapusnya.",
    page: 12,
  });

  const r = shot(s, "14_admin_anggota.jpg", 4.72, 1.80, 8.00, 5.00);
  legendItem(s, MX, 1.90, 1, "Form anggota baru", "Username, nama lengkap, password, NIK, divisi, dan status kepegawaian.", 3.8);
  legendItem(s, MX, 3.15, 2, "Daftar anggota", "Menampilkan username, nama, NIK tersamarkan, divisi, dan status.", 3.8);
  legendItem(s, MX, 4.40, 3, "Edit dan Hapus", "Perbarui data akun atau hapus akun yang tidak lagi digunakan.", 3.8);

  s.addShape(pptx.ShapeType.roundRect, {
    x: MX, y: 5.75, w: 3.78, h: 0.88,
    fill: { color: C.bluePale }, line: { color: "C7D8F5", width: 0.7 },
    rectRadius: 0.06,
  });
  txt(s, "Aturan akun", MX + 0.16, 5.86, 3.45, 0.22, { fontSize: 10.2, bold: true, color: C.blueDark });
  txt(s, "Password minimal 8 karakter; NIK harus 16 angka. Akun baru otomatis berperan Pengguna.", MX + 0.16, 6.10, 3.45, 0.45, {
    fontSize: 8.2, color: C.slate, lineSpacingMultiple: 1.04,
  });

  mark(s, r, 0.88, 0.20, 1);
  mark(s, r, 0.93, 0.67, 2);
  mark(s, r, 0.92, 0.76, 3);
  sourceNote(s, "Tangkapan layar: halaman Kelola Anggota. NIK dan data pribadi disamarkan.", 4.72, 6.86, 7.95);
  notes(s, "Akun hanya dibuat Admin melalui Kelola Anggota. Password minimal 8 karakter dan NIK 16 angka. Tidak ada registrasi mandiri.");
}

// ---------------------------------------------------------------------------
// 13. Ruang kerja Pengguna
// ---------------------------------------------------------------------------
{
  const s = pptx.addSlide({ masterName: "KONTEN" });
  base(s, {
    title: "Ruang kerja Pengguna",
    subtitle: "Dashboard menampilkan ringkasan pribadi; Daftar Barang menjadi katalog untuk mengajukan.",
    page: 13,
  });

  const r1 = shot(s, "16_user_dashboard.jpg", MX, 1.86, 5.95, 3.72);
  const r2 = shot(s, "17_user_barang.jpg", 6.78, 1.86, 5.95, 3.72);
  txt(s, "Dashboard: total pengajuan dan status", MX, 5.64, 5.95, 0.24, {
    fontSize: 9.2, bold: true, color: C.navy, align: "center",
  });
  txt(s, "Daftar Barang: katalog dan sisa stok", 6.78, 5.64, 5.95, 0.24, {
    fontSize: 9.2, bold: true, color: C.navy, align: "center",
  });
  sourceNote(s, "Tangkapan layar: Dashboard dan Daftar Barang milik akun Pengguna (data uji).", MX, 5.95, 12.1);

  const facts = [
    ["5 kartu", "Total, Menunggu, Disetujui, Ditolak, Selesai"],
    ["Katalog", "Cari barang, lihat satuan dan stok"],
    ["Jalan pintas", "Buat Pengajuan, Riwayat, dan Pesan"],
  ];
  let x = MX;
  for (const [t, d] of facts) {
    s.addShape(pptx.ShapeType.roundRect, {
      x, y: 6.20, w: 3.88, h: 0.56,
      fill: { color: C.pale }, line: { color: C.line, width: 0.7 },
      rectRadius: 0.06,
    });
    txt(s, t, x + 0.14, 6.20, 1.0, 0.56, { fontSize: 9.4, bold: true, color: C.blue, valign: "middle" });
    txt(s, d, x + 1.05, 6.20, 2.70, 0.56, { fontSize: 8.1, color: C.slate, valign: "middle" });
    x += 4.10;
  }
  mark(s, r1, 0.175, 0.30, 1);
  mark(s, r2, 0.62, 0.175, 2);
  notes(s, "Pengguna memiliki Dashboard, Buat Pengajuan, Daftar Barang, Riwayat Pengajuan, dan Pesan. Dashboard merangkum status; Daftar Barang adalah katalog dengan sisa stok.");
}

// ---------------------------------------------------------------------------
// 14. Buat pengajuan
// ---------------------------------------------------------------------------
{
  const s = pptx.addSlide({ masterName: "KONTEN" });
  base(s, {
    title: "Mengajukan barang dalam empat langkah",
    subtitle: "Pilih barang dari katalog, tentukan jumlah, lalu kirim. Beberapa barang dapat diajukan sekaligus.",
    page: 14,
  });

  const r1 = shot(s, "18_user_buat_pengajuan.jpg", MX, 1.80, 5.95, 3.55);
  const r2 = shot(s, "19_user_form_pengajuan.jpg", 6.78, 1.80, 5.95, 3.55);
  txt(s, "1. Pilih barang dari katalog", MX, 5.43, 5.95, 0.22, {
    fontSize: 9.0, bold: true, color: C.navy, align: "center",
  });
  txt(s, "2. Lengkapi formulir pengajuan", 6.78, 5.43, 5.95, 0.22, {
    fontSize: 9.0, bold: true, color: C.navy, align: "center",
  });

  const steps = [
    ["1", "Buka Buat Pengajuan Baru"],
    ["2", "Pilih barang dan isi jumlah minimal 1"],
    ["3", "Klik Tambah untuk barang lain"],
    ["4", "Periksa data, lalu klik Ajukan"],
  ];
  let x = MX;
  const sw = 2.96;
  for (const [n, t] of steps) {
    s.addShape(pptx.ShapeType.roundRect, {
      x, y: 5.78, w: sw, h: 0.84,
      fill: { color: C.white }, line: { color: C.line, width: 0.8 },
      rectRadius: 0.06,
    });
    s.addShape(pptx.ShapeType.ellipse, {
      x: x + 0.10, y: 5.90, w: 0.30, h: 0.30,
      fill: { color: C.blue }, line: { color: C.blue, width: 0 },
    });
    txt(s, n, x + 0.10, 5.90, 0.30, 0.30, {
      fontSize: 9.5, bold: true, color: C.white, align: "center", valign: "middle",
    });
    txt(s, t, x + 0.48, 5.83, sw - 0.55, 0.70, {
      fontSize: 8.3, color: C.slate, valign: "middle", lineSpacingMultiple: 1.0,
    });
    x += sw + 0.10;
  }

  s.addShape(pptx.ShapeType.roundRect, {
    x: MX, y: 6.76, w: 12.13, h: 0.32,
    fill: { color: C.bluePale }, line: { color: C.bluePale, width: 0 },
    rectRadius: 0.04,
  });
  txt(s, "Nama lengkap, divisi, dan status kepegawaian terisi otomatis dari akun. Catatan opsional maksimal 5.000 karakter.", MX + 0.12, 6.76, 11.9, 0.32, {
    fontSize: 8.0, color: C.blueDark, valign: "middle",
  });

  mark(s, r1, 0.35, 0.30, 1);
  mark(s, r2, 0.50, 0.50, 2);
  notes(s, "Pengguna memilih barang, mengisi jumlah, menambah baris bila perlu, lalu mengajukan. Nama, divisi, dan status kepegawaian terisi otomatis dari akun dan masih dapat disesuaikan.");
}

// ---------------------------------------------------------------------------
// 15. Riwayat dan bukti
// ---------------------------------------------------------------------------
{
  const s = pptx.addSlide({ masterName: "KONTEN" });
  base(s, {
    title: "Riwayat dan bukti penerimaan",
    subtitle: "Pantau progres, lalu unggah foto sebagai bukti bahwa barang benar-benar diterima.",
    page: 15,
  });

  const r1 = shot(s, "21_user_detail_riwayat.jpg", MX, 1.82, 5.70, 4.62);
  const r2 = shot(s, "20b_user_riwayat_selesai.jpg", 6.55, 1.82, 6.18, 3.86);
  txt(s, "Progres pengajuan dan bukti", MX, 6.50, 5.70, 0.22, {
    fontSize: 8.8, italic: true, color: C.muted, align: "center",
  });
  txt(s, "Riwayat: status dan tombol Detail", 6.55, 5.76, 6.18, 0.22, {
    fontSize: 8.8, italic: true, color: C.muted, align: "center",
  });

  s.addShape(pptx.ShapeType.roundRect, {
    x: 6.55, y: 6.05, w: 6.18, h: 0.78,
    fill: { color: C.pale }, line: { color: C.line, width: 0.7 },
    rectRadius: 0.06,
  });
  txt(s, "Alur bukti", 6.72, 6.10, 5.75, 0.20, { fontSize: 9.2, bold: true, color: C.navy });
  txt(s, "Unggah (JPG/PNG/WEBP, maks. 5 MB)  →  Menunggu verifikasi  →  Terverifikasi atau Perlu perbaikan.", 6.72, 6.32, 5.80, 0.38, {
    fontSize: 8.2, color: C.slate, lineSpacingMultiple: 1.02,
  });

  mark(s, r1, 0.52, 0.37, 1);
  mark(s, r1, 0.52, 0.72, 2);
  mark(s, r2, 0.89, 0.18, 3);
  notes(s, "Detail riwayat menampilkan linimasa Di ajukan, Diproses, Distribusi, Verifikasi bukti, Selesai. Bukti yang sudah diunggah dapat dilihat; status bukti menentukan penyelesaian. Bukti berupa JPG, PNG, atau WEBP maksimal 5 MB.");
}

// ---------------------------------------------------------------------------
// 16. Pesan
// ---------------------------------------------------------------------------
{
  const s = pptx.addSlide({ masterName: "KONTEN" });
  base(s, {
    title: "Pesan dari Admin: satu arah, tetap tercatat",
    subtitle: "Pesan distribusi memberi konteks kepada Pengguna, tetapi tidak mengubah status pengajuan.",
    page: 16,
  });

  const r1 = shot(s, "22_user_pesan.jpg", MX, 1.82, 5.95, 3.72);
  const r2 = shot(s, "23_user_detail_pesan.jpg", 6.78, 1.82, 5.95, 3.72);
  txt(s, "Daftar pesan dan jumlah belum dibaca", MX, 5.64, 5.95, 0.22, {
    fontSize: 8.8, italic: true, color: C.muted, align: "center",
  });
  txt(s, "Detail pesan dan tautan ke pengajuan", 6.78, 5.64, 5.95, 0.22, {
    fontSize: 8.8, italic: true, color: C.muted, align: "center",
  });

  const notesList = [
    ["Dibaca otomatis", "Membuka pesan menandainya Sudah dibaca dan mengurangi hitungan Belum Dibaca."],
    ["Tanpa tombol balas", "Tidak ada percakapan dua arah; sampaikan tanggapan melalui saluran resmi kantor."],
    ["Status tidak berubah", "Pesan hanya pemberitahuan. Status berubah lewat persetujuan, distribusi, dan verifikasi."],
  ];
  let x = MX;
  for (const [t, d] of notesList) {
    s.addShape(pptx.ShapeType.roundRect, {
      x, y: 5.98, w: 3.88, h: 0.92,
      fill: { color: C.pale }, line: { color: C.line, width: 0.7 },
      rectRadius: 0.06,
    });
    txt(s, t, x + 0.14, 6.07, 3.6, 0.22, { fontSize: 9.1, bold: true, color: C.navy });
    txt(s, d, x + 0.14, 6.30, 3.6, 0.54, { fontSize: 7.8, color: C.slate, lineSpacingMultiple: 1.03 });
    x += 4.10;
  }
  mark(s, r1, 0.45, 0.13, 1);
  mark(s, r2, 0.88, 0.37, 2);
  notes(s, "Pesan hanya dikirim Admin. Pengguna membaca pesan; membuka pesan menandainya sudah dibaca. Tidak ada balasan dan status pengajuan tidak berubah karena pesan.");
}

// ---------------------------------------------------------------------------
// 17. Tips dan batasan
// ---------------------------------------------------------------------------
{
  const s = pptx.addSlide({ masterName: "KONTEN" });
  base(s, {
    title: "Kebiasaan aman dan batasan sistem",
    subtitle: "Gunakan sistem sesuai alurnya dan ketahui fitur yang belum tersedia.",
    page: 17,
  });

  const cols = [
    {
      x: MX, title: "LAKUKAN", color: C.green, bg: C.greenBg,
      items: [
        "Logout setelah selesai, terutama di komputer bersama.",
        "Periksa nama, divisi, barang, dan jumlah sebelum mengirim.",
        "Verifikasi stok fisik sebelum menyetujui pengajuan.",
        "Simpan bukti penerimaan dan arsip laporan sebagai PDF.",
        "Jaga kerahasiaan password dan NIK.",
      ],
    },
    {
      x: 6.78, title: "HINDARI", color: C.red, bg: C.redBg,
      items: [
        "Berbagi akun atau password dengan rekan kerja.",
        "Mengubah data langsung di database atau file aplikasi.",
        "Mengirim pengajuan berulang untuk barang yang sama.",
        "Menghapus barang/anggota yang memiliki riwayat.",
        "Menganggap pesan sebagai bukti persetujuan.",
      ],
    },
  ];
  for (const col of cols) {
    s.addShape(pptx.ShapeType.roundRect, {
      x: col.x, y: 1.84, w: 5.95, h: 3.72,
      fill: { color: C.white }, line: { color: C.line, width: 0.8 },
      rectRadius: 0.07,
    });
    s.addShape(pptx.ShapeType.roundRect, {
      x: col.x + 0.28, y: 2.03, w: 1.30, h: 0.30,
      fill: { color: col.bg }, line: { color: col.bg, width: 0 },
      rectRadius: 0.5,
    });
    txt(s, col.title, col.x + 0.28, 2.03, 1.30, 0.30, {
      fontSize: 8.6, bold: true, color: col.color, align: "center", valign: "middle", charSpacing: 1,
    });
    let yy = 2.53;
    for (const it of col.items) {
      s.addShape(pptx.ShapeType.ellipse, {
        x: col.x + 0.31, y: yy + 0.06, w: 0.085, h: 0.085,
        fill: { color: col.color }, line: { color: col.color, width: 0 },
      });
      txt(s, it, col.x + 0.50, yy - 0.03, 5.15, 0.34, { fontSize: 9.0, color: C.slate });
      yy += 0.57;
    }
  }

  s.addShape(pptx.ShapeType.roundRect, {
    x: MX, y: 5.78, w: 12.13, h: 0.96,
    fill: { color: C.amberBg }, line: { color: "F2D28B", width: 0.7 },
    rectRadius: 0.06,
  });
  txt(s, "Belum tersedia atau terbatas", MX + 0.20, 5.88, 3.0, 0.22, { fontSize: 10.0, bold: true, color: C.amber });
  txt(s, "Peran vendor (login ditolak)  •  ekspor Excel/PDF langsung  •  notifikasi otomatis  •  ubah password di dalam aplikasi  •  riwayat mutasi stok  •  balasan pesan", MX + 0.20, 6.13, 11.68, 0.50, {
    fontSize: 8.5, color: C.slate, lineSpacingMultiple: 1.05,
  });
  notes(s, "Tekankan kebiasaan aman: logout, periksa data, verifikasi stok, dan simpan arsip. Sebutkan fitur yang belum ada agar ekspektasi pengguna tepat: vendor, ekspor langsung, notifikasi, ubah password, riwayat mutasi, balasan pesan.");
}

// ---------------------------------------------------------------------------
// 18. Penutup
// ---------------------------------------------------------------------------
{
  const s = pptx.addSlide();
  s.background = { color: C.navy };
  s.addShape(pptx.ShapeType.rect, {
    x: 0, y: 0, w: 0.14, h: H, fill: { color: C.blue }, line: { color: C.blue, width: 0 },
  });
  s.addShape(pptx.ShapeType.ellipse, {
    x: 9.5, y: -1.8, w: 5.8, h: 5.8,
    fill: { color: C.navy2, transparency: 60 }, line: { color: C.navy2, width: 0 },
  });

  s.addShape(pptx.ShapeType.ellipse, {
    x: 0.82, y: 0.72, w: 0.72, h: 0.72,
    fill: { color: C.white }, line: { color: C.white, width: 0 },
  });
  s.addImage({ path: LOGO, x: 0.87, y: 0.77, w: 0.62, h: 0.62 });

  txt(s, "TERIMA KASIH", 0.82, 1.95, 8.0, 0.34, {
    fontSize: 11, bold: true, color: C.sky, charSpacing: 2.8,
  });
  txt(s, "Gunakan SIBERSIH\nsesuai alurnya.", 0.82, 2.32, 8.4, 1.45, {
    fontSize: 35, bold: true, color: C.white, lineSpacingMultiple: 1.05,
  });
  s.addShape(pptx.ShapeType.rect, {
    x: 0.84, y: 3.98, w: 1.35, h: 0.05,
    fill: { color: C.blue }, line: { color: C.blue, width: 0 },
  });
  txt(s, "Data yang benar, pemeriksaan sebelum menekan tombol, dan pemantauan status secara berkala adalah kunci penggunaan yang aman.", 0.84, 4.20, 6.5, 0.80, {
    fontSize: 11, color: "C9DAEE", lineSpacingMultiple: 1.16,
  });

  s.addShape(pptx.ShapeType.roundRect, {
    x: 7.75, y: 2.15, w: 4.55, h: 3.55,
    fill: { color: "12335C" }, line: { color: "2A4C78", width: 0.8 },
    rectRadius: 0.08,
  });
  txt(s, "BANTUAN", 8.00, 2.40, 4.0, 0.24, {
    fontSize: 9, bold: true, color: C.sky, charSpacing: 1.8,
  });
  txt(s, "Admin Perlengkapan\nPLBN Wini", 8.00, 2.68, 4.0, 0.70, {
    fontSize: 15.5, bold: true, color: C.white, lineSpacingMultiple: 1.05,
  });
  s.addShape(pptx.ShapeType.rect, {
    x: 8.00, y: 3.45, w: 3.95, h: 0.011,
    fill: { color: "2A4C78" }, line: { color: "2A4C78", width: 0 },
  });
  txt(s, "plbnwini2017@gmail.com", 8.00, 3.62, 3.95, 0.25, {
    fontSize: 10.5, color: "D6E6F7",
  });
  txt(s, "Panduan lengkap tersedia dalam berkas PDF:\nPanduan_Penggunaan_SIBERSIH_PLBN_Wini.pdf", 8.00, 4.12, 3.95, 0.75, {
    fontSize: 9, color: "AFC4DD", lineSpacingMultiple: 1.1,
  });
  txt(s, "Edisi 2026  •  Dokumen internal", 8.00, 5.15, 3.95, 0.24, {
    fontSize: 8.2, color: "8FA9C7",
  });

  txt(s, "Badan Nasional Pengelola Perbatasan  •  Pos Lintas Batas Negara Wini", 0.84, 6.65, 8.4, 0.24, {
    fontSize: 8.5, color: "8FA9C7",
  });
  notes(s, "Tutup presentasi dengan mengingatkan tiga kunci: isi data dengan benar, periksa sebelum menekan tombol, dan pantau status. Arahkan peserta ke berkas PDF untuk panduan lengkap.");
}

// ---------------------------------------------------------------------------
// Simpan
// ---------------------------------------------------------------------------
pptx.writeFile({ fileName: OUT_PATH })
  .then(() => {
    const kb = Math.round(fs.statSync(OUT_PATH).size / 1024);
    console.log("PPTX dibuat:", OUT_PATH, "(" + kb + " KB, 18 slide)");
  })
  .catch((err) => {
    console.error("Gagal membuat PPTX:", err);
    process.exit(1);
  });
