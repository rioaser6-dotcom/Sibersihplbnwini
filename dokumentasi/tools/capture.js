/**
 * Mengambil screenshot asli aplikasi SIBERSIH PLBN Wini untuk dokumentasi.
 *
 * Pemakaian (dari folder dokumentasi/tools):
 *   set ADMIN_USER=admin
 *   set ADMIN_PASS=...
 *   set USER_SID=<PHPSESSID sesi Pengguna>
 *   node capture.js
 *
 * Skrip ini hanya membaca halaman dan membuka modal. Skrip tidak menyimpan,
 * menyetujui, menolak, mendistribusikan, atau menghapus data apa pun.
 */
const fs = require("fs");
const path = require("path");
const puppeteer = require("puppeteer-core");

function firstExisting(paths) {
  return paths.filter(Boolean).find((p) => fs.existsSync(p));
}

const CHROME = firstExisting([
  process.env.CHROME_PATH,
  "C:/Program Files/Google/Chrome/Application/chrome.exe",
  "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe",
  "C:/Program Files/Microsoft/Edge/Application/msedge.exe",
  "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe",
]);

const BASE = (process.env.BASE_URL || "http://127.0.0.1:8000").replace(/\/$/, "");
const OUT = path.resolve(__dirname, "../screenshots");
const HOST = new URL(BASE).hostname;
const USER_SID = process.env.USER_SID || "docsessuser0000000000003";
const PENGAJUAN_ID = process.env.PENGAJUAN_ID || "91";
const USER_ID = process.env.USER_ID || "7";
const ONLY = (process.env.ONLY || "")
  .split(",")
  .map((s) => s.trim())
  .filter(Boolean);

if (!CHROME) {
  console.error("Chrome/Edge tidak ditemukan. Set CHROME_PATH.");
  process.exit(1);
}

// Data pribadi dan isi pesan contoh disamarkan. Penggantian dilakukan pada
// tampilan browser saja; database tidak diubah.
const MASK_PAIRS = [
  ["Agustina Neno Elu", "Contoh Pengaju"],
  ["Agustina Elu", "pengguna.contoh"],
  ["ino", "Contoh Pengaju"],
  ["puki", "Contoh pesan distribusi dari Admin."],
];

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function maskSensitive(page) {
  await page.evaluate((pairs) => {
    const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
    const replacers = pairs.map(([from, to]) => ({
      re: new RegExp("(?<![\\p{L}\\p{N}])" + esc(from) + "(?![\\p{L}\\p{N}])", "gu"),
      to,
    }));
    const apply = (value) => {
      let v = value;
      for (const r of replacers) v = v.replace(r.re, r.to);
      // NIK dan nomor identitas 16 digit selalu disamarkan.
      v = v.replace(/\b\d{16}\b/g, "3201xxxxxxxxxxxx");
      return v;
    };
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    for (const node of nodes) {
      const next = apply(node.nodeValue);
      if (next !== node.nodeValue) node.nodeValue = next;
    }
    document.querySelectorAll("input, textarea, option").forEach((el) => {
      const attr = el.tagName === "OPTION" ? "textContent" : "value";
      const next = apply(el[attr] || "");
      if (next !== (el[attr] || "")) el[attr] = next;
    });
  }, MASK_PAIRS);
}

async function setSession(page, sid) {
  await page.setCookie({ name: "PHPSESSID", value: sid, domain: HOST, path: "/" });
}

async function clearSession(page) {
  const cookies = await page.cookies();
  if (cookies.length) await page.deleteCookie(...cookies);
}

async function login(page, user, pass) {
  await page.goto(BASE + "/login.php", { waitUntil: "networkidle2", timeout: 90000 });
  await page.type('input[name="username"]', user);
  await page.type('input[name="password"]', pass);
  await Promise.all([
    page.waitForNavigation({ waitUntil: "networkidle2", timeout: 90000 }),
    page.click('button[type="submit"], input[type="submit"]'),
  ]);
  if (/login\.php/.test(page.url())) {
    throw new Error("Login Admin gagal. Periksa ADMIN_USER/ADMIN_PASS.");
  }
}

async function capture(page, opts) {
  const {
    url,
    file,
    fullPage = false,
    click = null,
    wait = 800,
    media = null,
    element = null,
    scrollTo = null,
    scrollText = null,
    blurSelectors = null,
    clip = null,
  } = opts;

  // ONLY=22,23 membatasi pengambilan pada berkas tertentu saat memperbarui gambar.
  if (ONLY.length && !ONLY.some((key) => file.includes(key))) return;

  if (media) await page.emulateMediaType(media);
  await page.goto(BASE + url, { waitUntil: "networkidle2", timeout: 90000 });
  await maskSensitive(page);

  if (click) {
    await page.click(click);
    await sleep(900);
    await maskSensitive(page);
  }
  if (blurSelectors) {
    await page.evaluate((selectors) => {
      document.querySelectorAll(selectors).forEach((el) => {
        el.style.filter = "blur(14px)";
      });
    }, blurSelectors);
  }
  if (scrollTo !== null) {
    await page.evaluate((y) => window.scrollTo(0, y), scrollTo);
    await sleep(300);
  }
  if (scrollText) {
    await page.evaluate((text) => {
      const target = [...document.querySelectorAll("h2")].find((el) =>
        el.textContent.includes(text)
      );
      if (target) {
        target.scrollIntoView({ block: "start" });
        window.scrollBy(0, -18);
      }
    }, scrollText);
    await sleep(400);
  }
  await sleep(wait);

  const target = path.join(OUT, file);
  if (element) {
    const el = await page.$(element);
    if (!el) throw new Error("Elemen tidak ditemukan: " + element + " pada " + url);
    await el.screenshot({ path: target, type: "jpeg", quality: 90 });
  } else {
    const shot = { path: target, type: "jpeg", quality: 90, fullPage };
    if (clip) shot.clip = clip;
    await page.screenshot(shot);
  }
  console.log("tersimpan", file);

  if (media) await page.emulateMediaType("screen");
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: "new",
    args: [
      "--no-sandbox",
      "--disable-dev-shm-usage",
      "--font-render-hinting=none",
      "--hide-scrollbars",
      "--force-device-scale-factor=1",
    ],
    defaultViewport: { width: 1600, height: 1000, deviceScaleFactor: 1 },
  });
  const page = await browser.newPage();

  // ---------- Halaman tanpa login ----------
  await clearSession(page);
  await capture(page, { url: "/index.php", file: "01_landing.jpg" });
  await capture(page, { url: "/login.php", file: "02_login.jpg" });

  await page.goto(BASE + "/login.php", { waitUntil: "networkidle2" });
  await page.type('input[name="username"]', "admin");
  await page.type('input[name="password"]', "kata-sandi-salah");
  await Promise.all([
    page.waitForNavigation({ waitUntil: "networkidle2" }),
    page.click('button[type="submit"], input[type="submit"]'),
  ]);
  await maskSensitive(page);
  await page.screenshot({ path: path.join(OUT, "03_login_error.jpg"), type: "jpeg", quality: 90 });
  console.log("tersimpan 03_login_error.jpg");

  await capture(page, { url: "/lupa_password.php", file: "04_lupa_password.jpg" });

  // ---------- Admin ----------
  await clearSession(page);
  if (process.env.ADMIN_SID) {
    await setSession(page, process.env.ADMIN_SID);
  } else if (process.env.ADMIN_USER && process.env.ADMIN_PASS) {
    await login(page, process.env.ADMIN_USER, process.env.ADMIN_PASS);
  } else {
    throw new Error("Isi ADMIN_USER/ADMIN_PASS atau ADMIN_SID.");
  }

  await capture(page, { url: "/admin/dashboard.php", file: "05_admin_dashboard.jpg", fullPage: true });
  await capture(page, {
    url: "/admin/dashboard.php",
    file: "05b_admin_sidebar.jpg",
    element: ".sidebar",
  });
  await capture(page, { url: "/admin/barang.php", file: "06_admin_barang.jpg" });
  await capture(page, {
    url: "/admin/barang.php",
    file: "07_admin_barang_tambah.jpg",
    click: "#btnTampilkanForm",
    fullPage: true,
  });
  await capture(page, {
    url: "/admin/barang.php",
    file: "08_admin_barang_edit.jpg",
    click: "button[onclick^='openEditModal']",
  });
  await capture(page, { url: "/admin/pengajuan.php", file: "09_admin_pengajuan.jpg" });
  await page.setViewport({ width: 1600, height: 1280, deviceScaleFactor: 1 });
  await capture(page, {
    url: "/admin/pengajuan.php?detail=" + PENGAJUAN_ID,
    file: "10_admin_pengajuan_detail.jpg",
    fullPage: true,
  });
  await page.setViewport({ width: 1600, height: 1000, deviceScaleFactor: 1 });
  await page.setViewport({ width: 1600, height: 1320, deviceScaleFactor: 1 });
  await capture(page, {
    url: "/admin/distribusi.php",
    file: "11_admin_distribusi.jpg",
  });
  await page.setViewport({ width: 1600, height: 1000, deviceScaleFactor: 1 });
  await capture(page, { url: "/admin/laporan.php", file: "12_admin_laporan.jpg" });

  // Pratinjau cetak A4 memakai media print, dipotong pada bagian kop dan tabel.
  await page.setViewport({ width: 794, height: 1123, deviceScaleFactor: 2 });
  await capture(page, {
    url: "/admin/laporan.php",
    file: "13_admin_laporan_cetak.jpg",
    media: "print",
    clip: { x: 0, y: 0, width: 794, height: 548 },
    wait: 1000,
  });
  await page.setViewport({ width: 1600, height: 1000, deviceScaleFactor: 1 });

  await capture(page, { url: "/admin/anggota.php", file: "14_admin_anggota.jpg" });
  await capture(page, {
    url: "/admin/edit_pengguna.php?id=" + USER_ID,
    file: "15_admin_edit_pengguna.jpg",
  });

  // ---------- Pengguna ----------
  await clearSession(page);
  await setSession(page, USER_SID);

  // Dashboard diambil lebih dahulu agar penanda pesan belum dibaca masih tampil.
  await capture(page, { url: "/user/dashboard.php", file: "16_user_dashboard.jpg", fullPage: true });
  await capture(page, { url: "/user/lihat_barang.php", file: "17_user_barang.jpg" });
  await capture(page, { url: "/user/buat_pengajuan.php", file: "18_user_buat_pengajuan.jpg" });
  await capture(page, {
    url: "/user/buat_pengajuan.php",
    file: "19_user_form_pengajuan.jpg",
    click: "#btnTampilkanForm",
    fullPage: true,
  });
  await capture(page, { url: "/user/riwayat.php", file: "20_user_riwayat.jpg" });
  await capture(page, {
    url: "/user/riwayat.php",
    file: "20b_user_riwayat_selesai.jpg",
    scrollText: "Pengajuan #92",
  });
  await page.setViewport({ width: 1600, height: 1290, deviceScaleFactor: 1 });
  await capture(page, {
    url: "/user/detail_riwayat.php?id=" + PENGAJUAN_ID,
    file: "21_user_detail_riwayat.jpg",
    blurSelectors: ".proof-image",
    fullPage: true,
  });
  await page.setViewport({ width: 1600, height: 1000, deviceScaleFactor: 1 });
  await capture(page, { url: "/user/pesan.php", file: "22_user_pesan.jpg" });
  await capture(page, {
    url: "/user/detail_pesan.php?id=12",
    file: "23_user_detail_pesan.jpg",
    fullPage: true,
  });

  await browser.close();
  console.log("SELESAI");
})().catch((e) => {
  console.error("GAGAL:", e.message);
  process.exit(1);
});
