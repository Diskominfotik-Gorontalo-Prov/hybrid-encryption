# Referensi keamanan kriptografi

Dokumen ini merangkum sumber primer yang digunakan untuk menjelaskan algoritma
di plugin `aptika/laravel-hybrid-encryption`. Isinya membedakan antara:

- fakta dari standar atau dokumentasi resmi;
- perilaku kode plugin saat ini; dan
- batas klaim keamanan yang boleh dibuat.

Dokumen ini bukan sertifikasi keamanan, audit implementasi, atau jaminan bahwa
aplikasi telah memenuhi regulasi tertentu.

## Ringkasan implementasi plugin saat ini

Menurut source code plugin saat ini:

| Komponen | Perilaku plugin | Catatan keamanan |
| --- | --- | --- |
| Enkripsi data | `aes-256-gcm` melalui `openssl_encrypt` | AES-GCM menyediakan kerahasiaan dan authentication tag untuk mendeteksi perubahan payload. |
| Kunci AES | Default: hasil `SHA-256` atas `APP_KEY` dan label internal plugin. Override: AES key 32 byte yang diberikan aplikasi. | Derivasi APP_KEY adalah mekanisme khusus plugin, bukan mekanisme `APP_KEY` Laravel yang otomatis menjadi AES-GCM key. Mode override mengharuskan kedua aplikasi memakai AES key generated yang sama. |
| IV GCM | `random_bytes(12)` pada setiap payload | 12 byte adalah IV 96-bit. IV tidak rahasia dan harus ikut dikirim bersama ciphertext. Pengulangan IV dengan key GCM yang sama harus dihindari. |
| Pembungkusan kunci AES | RSA dengan `OPENSSL_PKCS1_OAEP_PADDING` | RSA hanya membungkus kunci AES, bukan seluruh data. Digest OAEP tidak dipilih eksplisit oleh kode saat ini; parameter aktual perlu diverifikasi terhadap versi OpenSSL/PHP yang digunakan. |
| RSA default | 3072 bit dari konfigurasi plugin | Berdasarkan tabel NIST, RSA 3072-bit dipetakan ke sekitar 128-bit security strength. |
| Payload | `encrypted_key`, `iv`, `tag`, dan `data` dalam Base64, serta `aes_source` sebagai metadata | Base64 hanya encoding, bukan enkripsi tambahan. `aes_source` tidak berisi AES key. |

## Apa arti “tingkat keamanan”

Tidak ada satu label universal seperti “aman tingkat dunia” yang dapat diberikan
hanya dari nama algoritma. NIST menggunakan istilah **security strength**,
yaitu perkiraan jumlah bit usaha yang diperlukan untuk mematahkan perlindungan
kripto dengan serangan terbaik yang relevan.

NIST SP 800-57 Part 1 Rev. 5 menekankan bahwa jika beberapa algoritma dan
ukuran kunci digunakan bersama, security strength sistem dibatasi oleh
komponen yang paling lemah. Tabel NIST memetakan secara umum:

- AES-128: sekitar 128-bit security strength;
- AES-192: sekitar 192-bit security strength;
- AES-256: sekitar 256-bit security strength;
- RSA 2048-bit: sekitar 112-bit security strength;
- RSA 3072-bit: sekitar 128-bit security strength;
- RSA 7680-bit: sekitar 192-bit security strength; dan
- RSA 15360-bit: sekitar 256-bit security strength.

Dengan konfigurasi default plugin, klaim konservatif untuk kombinasi RSA
3072-bit dan AES-256 adalah **sekitar 128-bit security strength pada level
algoritma**, karena RSA 3072 menjadi pembatas. Ini bukan berarti aplikasi
otomatis memenuhi seluruh persyaratan keamanan; pengelolaan key, autentikasi
endpoint, kontrol akses, logging, rotasi, backup, dan konfigurasi server juga
menentukan keamanan nyata.

Sumber primer:

- [NIST SP 800-57 Part 1 Rev. 5, Recommendation for Key Management: Part 1 – General](https://nvlpubs.nist.gov/nistpubs/specialpublications/nist.sp.800-57pt1r5.pdf), terutama pembahasan security strength dan tabel pemetaan algoritma/ukuran kunci.
- [Pengumuman NIST untuk SP 800-57 Part 1 Rev. 5](https://csrc.nist.gov/news/2020/nist-publishes-sp-800-57-pt-1-revision-5), yang menjelaskan cakupan panduan manajemen kunci dan pentingnya melindungi metadata, kontrol akses, autentikasi identitas, serta inventaris kunci.

## AES-GCM menurut NIST SP 800-38D

NIST SP 800-38D mendefinisikan GCM sebagai mode **authenticated encryption**
untuk cipher blok yang disetujui, termasuk AES. Dalam mode ini, proses enkripsi
menghasilkan ciphertext dan authentication tag. Saat decrypt, tag digunakan
untuk memberi jaminan bahwa ciphertext, AAD bila digunakan, dan IV tidak telah
diubah. Jika verifikasi gagal, implementasi harus memperlakukan hasil sebagai
gagal dan tidak menggunakan plaintext.

Plugin menyimpan tag pada field `tag` dan menyerahkannya kembali ke
`openssl_decrypt`. Karena source code tidak menggunakan AAD, perlindungan tag
pada implementasi ini berlaku untuk ciphertext dan parameter GCM yang dipakai,
bukan autentikasi identitas pengirim di tingkat aplikasi. RSA public/private key
memberikan kerahasiaan kunci AES, tetapi tidak menggantikan autentikasi pihak
pengirim atau TLS.

Source code membuat IV sepanjang 12 byte atau 96 bit. NIST SP 800-38D memberi
perhatian khusus pada pemilihan dan keunikan IV; penggunaan IV yang sama
dengan key GCM yang sama dapat merusak keamanan GCM. Karena kunci AES plugin
diturunkan deterministik dari `APP_KEY`, aplikasi harus sangat menjaga agar IV
tidak pernah berulang. `random_bytes(12)` membuat pengulangan sangat tidak
mungkin, tetapi bukan pengganti pengelolaan batas penggunaan dan pemantauan
pada sistem dengan volume pesan yang sangat besar.

NIST juga menjelaskan bahwa tag yang pendek atau pesan yang sangat panjang
mengurangi jaminan autentikasi, serta percobaan verifikasi yang gagal perlu
dipantau dan bila perlu dibatasi. Plugin menyimpan tag penuh yang dihasilkan
OpenSSL secara default; ukuran aktual tetap perlu diuji pada versi OpenSSL/PHP
yang dipakai dalam deployment.

Sumber primer:

- [NIST SP 800-38D, halaman publikasi resmi](https://csrc.nist.gov/pubs/sp/800/38/d/final).
- [NIST SP 800-38D, PDF lengkap](https://nvlpubs.nist.gov/nistpubs/Legacy/SP/nistspecialpublication800-38d.pdf), terutama Section 5–8 dan Appendix B–C tentang IV, authentication tag, jaminan autentikasi, batas tag, serta percobaan forgery.
- [NIST Glossary: Authenticated Encryption](https://csrc.nist.gov/glossary/term/authenticated_encryption).

## RSA-OAEP menurut RFC 8017

RFC 8017 mendefinisikan RSAES-OAEP sebagai skema enkripsi RSA untuk pesan
pendek. Panjang plaintext yang dapat dibungkus dibatasi oleh ukuran modulus RSA
dan ukuran hash OAEP: `mLen <= k - 2*hLen - 2`. Karena itu pola hybrid encryption
yang dipakai plugin benar: data besar dienkripsi AES, kemudian hanya kunci AES
yang dibungkus RSA.

RFC 8017 juga menyatakan bahwa pilihan hash dan mask generation function OAEP
harus ditetapkan untuk suatu RSA key. Nilai default yang tercantum dalam RFC
menggunakan SHA-1/MGF1-SHA-1, walaupun RFC juga mendefinisikan parameter OAEP
secara eksplisit dan implementasi modern dapat memilih hash yang lebih kuat.

Implementasi plugin memanggil `openssl_public_encrypt` dan
`openssl_private_decrypt` dengan `OPENSSL_PKCS1_OAEP_PADDING`, tetapi tidak
memberikan parameter digest OAEP secara eksplisit. Oleh sebab itu README tidak
boleh mengklaim “RSA-OAEP-SHA-256” untuk plugin saat ini. Jika interoperabilitas
lintas aplikasi mensyaratkan SHA-256 untuk OAEP, parameter tersebut harus dibuat
eksplisit dan diuji pada kedua runtime PHP/OpenSSL.

Sumber primer:

- [RFC 8017: PKCS #1 v2.2](https://www.rfc-editor.org/rfc/rfc8017.html), terutama Section 7.1 tentang RSAES-OAEP dan batas panjang pesan.
- [RFC 8017 versi informasi dan metadata RFC Editor](https://www.rfc-editor.org/info/rfc8017/).
- [PHP Manual: `openssl_public_encrypt`](https://www.php.net/manual/en/function.openssl-public-encrypt.php), yang mendokumentasikan `OPENSSL_PKCS1_OAEP_PADDING`, pasangan public/private key, dan parameter digest pada PHP yang mendukungnya.

## PHP OpenSSL yang relevan

Dokumentasi PHP menyatakan bahwa parameter `passphrase` pada
`openssl_encrypt` **bukan key derivation function**. Jika panjangnya tidak
sesuai, OpenSSL akan melakukan padding NUL atau pemotongan. Plugin karena itu
tidak menyerahkan nilai teks `APP_KEY` mentah sebagai kunci AES; plugin terlebih
dahulu mendecode format `base64:` Laravel bila ada, lalu melakukan hash SHA-256
dengan label internal untuk menghasilkan 32 byte.

Dokumentasi PHP juga mendokumentasikan bahwa mode AEAD seperti GCM menghasilkan
authentication tag melalui parameter by-reference, dan bahwa IV serta tag harus
tersedia ketika decrypt. Ini sesuai dengan field payload plugin `iv` dan `tag`.

Sumber primer:

- [PHP Manual: `openssl_encrypt`](https://www.php.net/manual/en/function.openssl-encrypt.php), termasuk catatan bahwa `passphrase` bukan KDF dan dokumentasi parameter IV, tag, AAD, serta ukuran tag.
- [PHP Manual: `openssl_public_encrypt`](https://www.php.net/manual/en/function.openssl-public-encrypt.php).
- [PHP Manual: persyaratan ekstensi OpenSSL](https://www.php.net/manual/en/openssl.requirements.php), termasuk anjuran memakai versi OpenSSL yang masih dipelihara.

## Hubungan dengan `APP_KEY` Laravel

Dokumentasi Laravel menjelaskan bahwa encryption service Laravel memakai kunci
pada konfigurasi `app.key`, yang biasanya berasal dari `APP_KEY`, dan bahwa
`key:generate` menghasilkan nilai menggunakan generator byte acak yang aman.
Dokumentasi Laravel juga menjelaskan cipher Laravel dan integritas nilai yang
dienkripsi oleh encrypter Laravel.

Namun plugin ini bukan `Crypt` facade Laravel. Plugin memiliki derivasi sendiri:

```text
APP_KEY Laravel
  -> hapus prefix base64: bila ada
  -> SHA-256(label internal + material APP_KEY)
  -> 32 byte AES key untuk AES-256-GCM
```

Jika aplikasi memberikan `$aesKey`, alur tersebut diganti dengan validasi AES
key 32 byte yang diberikan. Plugin tidak menyimpan AES generated. Nilai yang
sama harus dikelola dan diberikan kembali saat decrypt.

Konsekuensinya:

1. Mode `app_key` pada App1 dan App2 yang harus saling bertukar payload harus
   memakai nilai `APP_KEY` yang sama persis dan konfigurasi derivasi yang sama.
2. Mode `generated` harus memakai AES key generated yang sama pada App1 dan App2.
3. Mengganti `APP_KEY` membuat AES key mode `app_key` berubah. Payload lama tidak
   dapat didekripsi dengan key hasil derivasi baru.
4. `APP_KEY` harus diperlakukan sebagai secret lintas aplikasi dalam skenario
   ini. Jangan menaruhnya pada payload, log, repository, atau frontend.
5. Menyamakan `APP_KEY` antar aplikasi memperluas dampak kebocoran satu aplikasi.
   Untuk desain dengan blast radius lebih kecil, gunakan secret khusus integrasi
   atau KDF/key-management terpisah; perubahan itu memerlukan perubahan desain
   plugin dan kontrak payload.
6. Command plugin menampilkan fingerprint AES. Mode `generated` juga menampilkan
   nilai AES satu kali agar pengguna dapat menyimpannya di secret manager; nilai
   tersebut tidak disimpan oleh package.

Sumber primer:

- [Laravel Encryption Documentation](https://laravel.com/docs/12.x/encryption), tentang `APP_KEY`, `app.key`, cipher, dan encrypter Laravel. Versi URL dapat disesuaikan dengan versi Laravel aplikasi.
- [Laravel API: `Illuminate\\Encryption\\Encrypter`](https://api.laravel.com/docs/12.x/Illuminate/Encryption/Encrypter.html), sebagai referensi API resmi mengenai key, cipher, encrypt, decrypt, dan validasi payload.

## Batas klaim keamanan yang aman untuk dokumentasi

Klaim berikut aman bila konfigurasi dan operasi plugin sesuai source code:

- Plugin menggunakan AES-256-GCM untuk kerahasiaan dan verifikasi perubahan
  ciphertext melalui authentication tag.
- Plugin menggunakan RSA-OAEP untuk membungkus kunci AES dengan pasangan RSA
  public/private key.
- Dengan RSA 3072-bit, NIST memetakan komponen RSA tersebut ke sekitar 128-bit
  security strength.
- Payload mode `app_key` yang dibuat dengan `APP_KEY` berbeda akan gagal melewati
  pemeriksaan kesamaan kunci AES plugin.
- Payload mode `generated` yang didekripsi dengan AES key berbeda akan ditolak.

Klaim berikut tidak boleh dibuat tanpa perubahan dan audit tambahan:

- “Plugin tersertifikasi NIST/FIPS.” Menggunakan algoritma yang dibahas NIST
  bukan berarti implementasi atau produk tersertifikasi.
- “Keamanan 256-bit end-to-end.” Kombinasi ini dibatasi RSA 3072 sekitar
  128-bit menurut pemetaan NIST, dan keamanan sistem juga dibatasi oleh key
  management serta runtime.
- “RSA-OAEP-SHA-256.” Source code saat ini tidak memilih digest OAEP secara
  eksplisit.
- “APP_KEY adalah AES key Laravel.” Plugin menurunkan key baru dengan SHA-256;
  ini bukan perilaku otomatis Laravel Encrypter.
- “Payload membuktikan identitas App1.” Enkripsi dan authentication tag tidak
  otomatis menyediakan autentikasi identitas pengirim. Gunakan TLS, autentikasi
  API, tanda tangan digital, atau mekanisme identitas yang sesuai kebutuhan.

## Checklist operasional

- [ ] Gunakan PHP dan OpenSSL yang masih dipelihara.
- [ ] Simpan private key pada disk private dengan permission paling ketat yang
      didukung deployment.
- [ ] Jangan kirim private key atau `APP_KEY` ke aplikasi client/browser.
- [ ] Gunakan TLS dan autentikasi endpoint; enkripsi payload bukan pengganti TLS.
- [ ] Pastikan IV GCM tidak pernah digunakan ulang dengan AES key yang sama.
- [ ] Simpan AES generated di secret manager/environment jika mode `generated`
      digunakan; package tidak menyediakan penyimpanan ulang.
- [ ] Batasi percobaan decrypt/tag yang gagal dan catat kejadian tanpa mencatat
      plaintext, `APP_KEY`, private key, atau AES key.
- [ ] Rencanakan rotasi RSA key dan `APP_KEY`; rotasi `APP_KEY` memerlukan
      strategi migrasi karena payload lama bergantung pada key derivasi lama.
- [ ] Uji interoperabilitas PHP/OpenSSL untuk parameter OAEP yang benar-benar
      dipakai sebelum menghubungkan dua aplikasi.

## Catatan penelitian

Penelitian dilakukan pada 24 September 2026 menggunakan sumber primer yang
ditautkan di atas. Standar, dokumentasi PHP, OpenSSL, dan Laravel dapat berubah;
verifikasi ulang versi yang berlaku sebelum membuat keputusan kepatuhan,
sertifikasi, atau threat model produksi.
