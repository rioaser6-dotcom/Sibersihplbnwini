/**
 * Membangun PDF panduan dari berkas Markdown.
 *
 * Cara pakai (dari folder ini):
 *   npm install
 *   node build.js
 *
 * Prasyarat:
 *   - Google Chrome atau Microsoft Edge terpasang.
 *   - Berkas Markdown dan folder screenshots/ berada satu tingkat di atas folder ini.
 *
 * Variabel lingkungan opsional:
 *   CHROME_PATH  Lokasi chrome.exe / msedge.exe bila tidak terdeteksi otomatis.
 */
const fs = require("fs");
const path = require("path");
const { marked } = require("marked");
const puppeteer = require("puppeteer-core");
const { PDFDocument, StandardFonts, rgb } = require("pdf-lib");

const ROOT = path.resolve(__dirname, "../..");
const DOC_DIR = path.resolve(__dirname, "..");
const MD_PATH = path.join(DOC_DIR, "Sumber_Panduan.md");
const PDF_PATH = path.join(DOC_DIR, "Panduan_Penggunaan_SIBERSIH_PLBN_Wini.pdf");

function firstExisting(paths) {
  return paths.filter(Boolean).find((p) => fs.existsSync(p));
}

const CHROME = firstExisting([
  process.env.CHROME_PATH,
  "C:/Program Files/Google/Chrome/Application/chrome.exe",
  "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe",
  "C:/Program Files/Microsoft/Edge/Application/msedge.exe",
  "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe",
  "/usr/bin/google-chrome",
  "/usr/bin/chromium",
  "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
]);

if (!CHROME) {
  console.error("Chrome/Edge tidak ditemukan. Set variabel lingkungan CHROME_PATH.");
  process.exit(1);
}

const LOGO = pathToFileUrl(path.join(DOC_DIR, "assets/logo-bnpp.png"));
const COVER_IMG = pathToFileUrl(
  firstExisting([
    path.join(DOC_DIR, "screenshots/05_admin_dashboard.jpg"),
    path.join(DOC_DIR, "screenshots/05_admin_dashboard.png"),
  ]) || path.join(DOC_DIR, "screenshots/05_admin_dashboard.jpg")
);

function pathToFileUrl(p) {
  return "file:///" + p.replace(/\\/g, "/").replace(/ /g, "%20");
}

function readHeadings(md) {
  const lines = md.split(/\r?\n/);
  const headings = [];
  let inFence = false;
  for (const line of lines) {
    if (/^```/.test(line.trim())) inFence = !inFence;
    if (inFence) continue;
    const m = line.match(/^(#{1,2})\s+(.+?)\s*$/);
    if (!m) continue;
    headings.push({ level: m[1].length, text: m[2].replace(/\*\*/g, "").trim() });
  }
  return headings;
}

function buildToc(headings, pageMap) {
  let html = "";
  let n = 0;
  for (const h of headings) {
    const id = "h-" + n;
    const page = pageMap ? (pageMap[id] ?? "") : "00";
    html += `<div class="toc-entry lvl${h.level}"><span class="toc-title">${escapeHtml(h.text)}</span><span class="toc-dots"></span><span class="toc-page">${page}</span></div>\n`;
    n++;
  }
  return html;
}

function escapeHtml(s) {
  return s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
}

function buildHtml(md, pageMap) {
  const headings = readHeadings(md);

  let headingIndex = 0;
  const renderer = new marked.Renderer();
  renderer.heading = function (token) {
    const level = token.depth;
    const id = "h-" + headingIndex;
    headingIndex++;
    if (level <= 2) {
      return `<h${level} id="${id}">${token.text}</h${level}>\n`;
    }
    return `<h${level}>${token.text}</h${level}>\n`;
  };

  let body = marked.parse(md, { renderer, gfm: true, breaks: false });

  // Ubah gambar tunggal menjadi figure dengan caption dari alt text.
  body = body.replace(
    /<p>\s*<img([^>]*?)alt="([^"]*)"([^>]*?)src="([^"]*)"([^>]*?)>\s*<\/p>/g,
    (m, a1, alt, a2, src, a3) => {
      const cls = /05b_/.test(src) ? " sidebar" : /13_admin_laporan_cetak/.test(src) ? " print" : /alur_/.test(src) ? " diagram" : "";
      return `<figure class="fig${cls}"><img src="${src}" alt="${alt}"><figcaption>${alt}</figcaption></figure>`;
    }
  );
  body = body.replace(
    /<p>\s*<img([^>]*?)src="([^"]*)"([^>]*?)alt="([^"]*)"([^>]*?)>\s*<\/p>/g,
    (m, a1, src, a2, alt, a3) => {
      const cls = /05b_/.test(src) ? " sidebar" : /13_admin_laporan_cetak/.test(src) ? " print" : /alur_/.test(src) ? " diagram" : "";
      return `<figure class="fig${cls}"><img src="${src}" alt="${alt}"><figcaption>${alt}</figcaption></figure>`;
    }
  );

  // Hanya daftar bernomor tingkat teratas yang diberi label "Langkah".
  {
    let out = "";
    let depth = 0;
    let i = 0;
    while (i < body.length) {
      if (body.startsWith("<ol>", i)) {
        out += depth === 0 ? '<ol class="steps">' : "<ol>";
        depth += 1;
        i += 4;
        continue;
      }
      if (body.startsWith("</ol>", i)) {
        depth = Math.max(0, depth - 1);
        out += "</ol>";
        i += 5;
        continue;
      }
      out += body[i];
      i += 1;
    }
    body = out;
  }

  const toc = buildToc(headings, pageMap);

  return `<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Panduan Penggunaan SIBERSIH PLBN Wini</title>
<style>${css()}</style>
</head>
<body>

<section class="cover">
  <div class="cover-top">
    <img class="cover-logo" src="${LOGO}" alt="Logo BNPP">
    <div class="cover-org">
      <div class="org-1">BADAN NASIONAL PENGELOLA PERBATASAN</div>
      <div class="org-2">POS LINTAS BATAS NEGARA WINI</div>
    </div>
  </div>

  <div class="cover-main">
    <div class="cover-kicker">PANDUAN PENGGUNAAN</div>
    <h1 class="cover-title">SIBERSIH<br>PLBN WINI</h1>
    <div class="cover-line"></div>
    <div class="cover-subtitle">Sistem Informasi Pengajuan dan Distribusi<br>Barang Kebersihan</div>
    <p class="cover-desc">Panduan langkah demi langkah untuk Admin dan Pengguna, disusun berdasarkan aplikasi yang berjalan.</p>
  </div>

  <div class="cover-shot">
    <img src="${COVER_IMG}" alt="Tampilan Dashboard Admin">
  </div>

  <div class="cover-bottom">
    <span>Edisi Oktober 2026</span>
    <span>Dokumen untuk pengguna internal</span>
  </div>
</section>

<section class="toc-section">
  <div class="toc-heading">DAFTAR ISI</div>
  <div class="toc">
${toc}
  </div>
  <div class="toc-note">Nomor halaman pada daftar ini mengacu pada nomor halaman cetak di bagian bawah halaman.</div>
</section>

<main class="content">
${body}
</main>

</body>
</html>`;
}

function css() {
  return `
@page { size: A4; margin: 19mm 17mm 20mm 17mm; }
@page :first { margin: 0; }
html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body { margin:0; font-family: "Segoe UI", "Inter", Arial, sans-serif; font-size: 10.5pt; line-height: 1.58; color:#1f2937; }
code { font-family: Consolas, "Courier New", monospace; font-size: 9.2pt; background:#f1f5f9; border:0.5pt solid #e2e8f0; border-radius:2pt; padding:0.5pt 2.5pt; }
p { margin: 0 0 7pt; text-align: justify; }
strong { color:#0f172a; }
h1 { font-size: 19pt; line-height:1.25; color:#0f2c5c; page-break-before: always; border-bottom: 2.5pt solid #1d4ed8; padding-bottom: 7pt; margin: 0 0 14pt; }
h1:first-child { page-break-before: auto; }
h2 { font-size: 13pt; color:#1e40af; margin: 14pt 0 5pt; page-break-after: avoid; }
h3 { font-size: 11pt; color:#334155; margin: 12pt 0 4pt; page-break-after: avoid; }
ul, ol { margin: 0 0 8pt; padding-left: 16pt; }
li { margin-bottom: 3pt; }
ol.steps { list-style: none; counter-reset: step; padding-left: 6pt; }
ol.steps > li { counter-increment: step; position: relative; padding-left: 27mm; margin-bottom: 5pt; page-break-inside: avoid; }
ol.steps > li::before { content: "Langkah " counter(step); position:absolute; left:0; top:0; width: 24mm; font-weight:700; color:#1d4ed8; font-size: 9.3pt; }
table { width:100%; border-collapse: collapse; font-size: 8.8pt; line-height:1.45; margin: 6pt 0 12pt; page-break-inside: auto; }
thead { display: table-header-group; }
tr { page-break-inside: avoid; }
th { background:#e8eefb; border:0.7pt solid #a9b8d0; padding:5pt 6pt; text-align:left; color:#0f2c5c; font-size:9pt; }
td { border:0.7pt solid #cbd5e1; padding:4.5pt 6pt; vertical-align: top; }
tbody tr:nth-child(even) td { background:#f8fafc; }
blockquote { background:#eff6ff; border-left:4pt solid #2563eb; margin:8pt 0 10pt; padding:8pt 10pt; font-size:9.6pt; page-break-inside: avoid; }
blockquote p { margin:0; text-align:left; }
figure { margin: 7pt 0 9pt; page-break-inside: avoid; text-align:center; }
figure img { width:88%; margin:0 auto; border:0.7pt solid #cbd5e1; border-radius:3pt; display:block; }
figure.sidebar img { width: 38mm; }
figure.print img { width: 112mm; }
figure.diagram { margin: 10pt 0 12pt; }
figure.diagram img { width: 100%; border: 0; border-radius: 0; }
figcaption { font-size: 8.6pt; color:#475569; margin-top: 4pt; font-style: italic; }
/* ---------- SAMPUL ---------- */
.cover { width:210mm; height:297mm; background: linear-gradient(160deg,#081a33 0%,#0b2a52 45%,#123f74 100%); color:#fff; position:relative; overflow:hidden; page-break-after: always; }
.cover-top { position:absolute; top:16mm; left:16mm; right:16mm; display:flex; align-items:center; gap:6mm; }
.cover-logo { width:22mm; height:22mm; object-fit:contain; background:#fff; border-radius:50%; padding:1.5mm; }
.cover-org .org-1 { font-size:10.5pt; font-weight:700; letter-spacing:0.4pt; }
.cover-org .org-2 { font-size:9pt; color:#bcd4f0; margin-top:1.5mm; letter-spacing:0.3pt; }
.cover-main { position:absolute; top:52mm; left:16mm; right:16mm; }
.cover-kicker { font-size:10pt; letter-spacing:3pt; color:#7fb2ee; font-weight:700; }
.cover-title { font-size:35pt; line-height:1.1; margin:5mm 0 0; color:#fff; border:0; padding:0; page-break-before:auto; font-weight:800; letter-spacing:0.5pt; }
.cover-line { width:38mm; height:1.4mm; background:#3b82f6; margin:7mm 0 5mm; }
.cover-subtitle { font-size:15pt; font-weight:600; color:#e8f1fb; }
.cover-desc { font-size:10pt; color:#b9cde6; margin-top:5mm; max-width:120mm; text-align:left; }
.cover-shot { position:absolute; left:16mm; right:16mm; top:145mm; }
.cover-shot img { width:100%; border-radius:2.5mm; border:0.8mm solid rgba(255,255,255,0.18); box-shadow:0 6mm 14mm rgba(0,0,0,0.35); }
.cover-bottom { position:absolute; bottom:12mm; left:16mm; right:16mm; display:flex; justify-content:space-between; font-size:8.8pt; color:#9fb8d6; border-top:0.3mm solid rgba(255,255,255,0.25); padding-top:3.5mm; }
/* ---------- DAFTAR ISI ---------- */
.toc-section { page-break-after: always; }
.toc-heading { font-size:19pt; font-weight:800; color:#0f2c5c; border-bottom:2.5pt solid #1d4ed8; padding-bottom:6pt; margin-bottom:7pt; letter-spacing:0.5pt; }
.toc-entry { padding: 0.1pt 0 0; border-bottom: 0.5pt dotted #cbd5e1; font-size: 8.4pt; line-height: 1.18; }
.toc-entry.lvl1 { font-weight:700; color:#0f172a; margin-top: 1.4pt; font-size:8.9pt; border-bottom: 0.7pt solid #cbd5e1; }
.toc-entry.lvl2 { padding-left: 4.5mm; color:#334155; font-size:8.4pt; }
.toc-dots { display:none; }
.toc-title { display:inline-block; max-width: 150mm; }
.toc-page { float:right; font-variant-numeric: tabular-nums; }
.toc-note { margin-top:7pt; font-size:8.2pt; color:#64748b; font-style:italic; }
`;
}

async function renderPdf(html, outPath) {
  const htmlPath = path.join(DOC_DIR, "_build.html");
  fs.writeFileSync(htmlPath, html, "utf8");
  const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: "new",
    args: ["--no-sandbox", "--hide-scrollbars", "--font-render-hinting=none", "--allow-file-access-from-files"],
  });
  const page = await browser.newPage();
  await page.goto(pathToFileUrl(htmlPath), { waitUntil: "networkidle0", timeout: 120000 });
  await page.evaluate(async () => {
    await Promise.all(
      Array.from(document.images).map((img) =>
        img.complete
          ? Promise.resolve()
          : new Promise((resolve) => {
              img.onload = resolve;
              img.onerror = resolve;
            })
      )
    );
  });
  await page.emulateMediaType("print");
  await new Promise((r) => setTimeout(r, 500));
  await page.pdf({
    path: outPath,
    format: "A4",
    printBackground: true,
    preferCSSPageSize: true,
    margin: { top: 0, right: 0, bottom: 0, left: 0 },
  });
  await browser.close();
  fs.unlinkSync(htmlPath);
}

function normalizeHeading(s) {
  return s.toLowerCase().replace(/[^a-z0-9]+/g, "");
}

async function extractHeadingPages(pdfPath, headings) {
  const pdfjs = await import("pdfjs-dist/legacy/build/pdf.mjs");
  const data = new Uint8Array(fs.readFileSync(pdfPath));
  const doc = await pdfjs.getDocument({ data, useSystemFonts: true }).promise;
  const pageText = [];
  for (let i = 1; i <= doc.numPages; i++) {
    const page = await doc.getPage(i);
    const content = await page.getTextContent();
    // Judul bab/subbab tampil lebih besar daripada teks isi dan daftar isi.
    const big = content.items
      .filter((it) => (it.height || 0) >= 12)
      .map((it) => it.str)
      .join(" ");
    pageText[i] = normalizeHeading(big);
  }
  const map = {};
  let start = 1;
  for (let i = 0; i < headings.length; i++) {
    const target = normalizeHeading(headings[i].text);
    let found = null;
    for (let p = start; p <= doc.numPages; p++) {
      if (pageText[p] && pageText[p].includes(target)) {
        found = p;
        break;
      }
    }
    if (found) {
      map["h-" + i] = found - 1;
      start = found;
    }
  }
  return { map, pages: doc.numPages, pageText };
}

async function stampFooter(pdfPath) {
  const bytes = fs.readFileSync(pdfPath);
  const pdfDoc = await PDFDocument.load(bytes);
  pdfDoc.setTitle("Panduan Penggunaan SIBERSIH PLBN Wini");
  pdfDoc.setSubject("Panduan Penggunaan Sistem Informasi Pengajuan dan Distribusi Barang Kebersihan PLBN Wini");
  pdfDoc.setCreator("SIBERSIH PLBN Wini");
  pdfDoc.setKeywords(["panduan penggunaan", "PLBN Wini", "SIBERSIH"]);
  const font = await pdfDoc.embedFont(StandardFonts.Helvetica);
  const pages = pdfDoc.getPages();
  const mm = (v) => (v * 72) / 25.4;
  pages.forEach((page, index) => {
    const { width } = page.getSize();
    if (index > 0) {
      const label = String(index);
      const text = "Panduan Penggunaan SIBERSIH PLBN Wini";
      page.drawText(text, {
        x: mm(17),
        y: mm(10),
        size: 7.5,
        font,
        color: rgb(0.42, 0.47, 0.55),
      });
      page.drawText(label, {
        x: width - mm(17) - font.widthOfTextAtSize(label, 8),
        y: mm(10),
        size: 8,
        font,
        color: rgb(0.25, 0.3, 0.38),
      });
      page.drawLine({
        start: { x: mm(17), y: mm(13.5) },
        end: { x: width - mm(17), y: mm(13.5) },
        thickness: 0.4,
        color: rgb(0.8, 0.84, 0.89),
      });
    }
  });
  fs.writeFileSync(pdfPath, await pdfDoc.save());
}

(async () => {
  const md = fs.readFileSync(MD_PATH, "utf8");
  const tmp = path.join(DOC_DIR, "_pass1.pdf");

  const headings = readHeadings(md);

  console.log("Pass 1: rendering with placeholders...");
  await renderPdf(buildHtml(md, null), tmp);
  const { map, pages } = await extractHeadingPages(tmp, headings);
  const missing = headings.filter((h, i) => map["h-" + i] === undefined);
  if (missing.length) {
    console.warn("Heading page not detected:", missing.map((h) => h.text));
  }

  console.log("Pass 2: rendering with page numbers...");
  await renderPdf(buildHtml(md, map), PDF_PATH);
  fs.unlinkSync(tmp);

  await stampFooter(PDF_PATH);
  console.log("PDF written:", PDF_PATH);
  const finalInfo = await extractHeadingPages(PDF_PATH, headings);
  console.log("Pages:", finalInfo.pages);
  const finalMissing = headings.filter((h, i) => finalInfo.map["h-" + i] === undefined);
  if (finalMissing.length) {
    console.warn("Final check, headings without page number:", finalMissing.map((h) => h.text));
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
