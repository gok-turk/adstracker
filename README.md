# AdsTracker Pro 🛡️
### Çoklu Domain Google Ads Sahte Tıklama Önleme & IP Güvenlik Sistemi
*(Multi-Site Google Ads Click Fraud Prevention & IP Tracker)*

[![PHP Version](https://img.shields.io/badge/PHP-7.4%20%7C%208.x-blue.svg)](https://php.net)
[![Database](https://img.shields.io/badge/Database-SQLite%20(Zero--Config)-green.svg)](https://sqlite.org)
[![License](https://img.shields.io/badge/License-MIT-amber.svg)](LICENSE)
[![Status](https://img.shields.io/badge/Status-Production%20Ready-brightgreen.svg)]()

**AdsTracker Pro**, Google Ads arama ağı ve performans reklamlarınız üzerinden web sitelerinize gelen ziyaretçileri anlık olarak takip eden, rakip firmaların veya botların bütçenizi tüketmek amacıyla yaptığı kasıtlı/sahte tıklamaları (**Click Fraud**) tespit edip engelleyen, tamamen bağımsız ve taşınabilir (portable) bir güvenlik yazılımıdır.

Aylık yüksek abonelik ücretleri talep eden yabancı servisler yerine kendi sunucunuza tek seferde kurup ömür boyu **sınırsız web sitesi (domain)** için kullanabilirsiniz.

---

## 🌟 Öne Çıkan Özellikler

- **Çoklu Domain (Multi-Tenant) Desteği:** Tek bir ana panel üzerinden dilediğiniz kadar domain ekleyin. Her sitenin reklam trafiği, tıklama eşikleri ve şüpheli IP listeleri tamamen birbirinden izole tutulur.
- **Doğrudan Kökten Giriş (Clean Routing):** Ana alan adına girildiği anda (`https://tracker.siteniz.com/`) sistem otomatik olarak giriş ekranına yönlendirir, elle `/admin/` yazma zorunluluğu yoktur.
- **Google Ads Tek Tıkla Negatifleme:** Tespit edilen sahte IP'leri tek tıkla kopyalayabilir, doğrudan *Google Ads > Kampanya Ayarları > IP Dışlamaları* alanına yapıştırarak reklam gösterimini o kişilere tamamen kapatabilirsiniz.
- **Çift Katmanlı Koruma Mimarisi:**
  1. **Evrensel JavaScript Etiketi:** HTML, WordPress, Shopify, Wix veya özel yazılım fark etmeksizin tek satırlık kodla asenkron izleme.
  2. **PHP Shield İstemcisi (`shield-client.php`):** Sunucu seviyesinde koruma; sahte tıklayan rakip veya bot siteniz hiç yüklenmeden doğrudan **HTTP 403 Forbidden** ile durdurulur (sunucu kaynakları ve bant genişliği korunur).
  3. **Google Tag Manager (GTM):** Özel HTML etiketi olarak tek tıkla canlıya alma.
- **Gerçek İstemci IP Tespiti:** Cloudflare, Proxy veya VPN arkasındaki gerçek IP adreslerini (`CF-Connecting-IP`, `X-Forwarded-For` vb.) çözer.
- **Detaylı Cihaz & Parmak İzi:** Ziyaretçinin mobil modelini (iPhone, Samsung, Xiaomi), tarayıcısını, işletim sistemini ve ekran çözünürlüğünü analiz eder.
- **Tıklama Sıklığı & Otomatik Blokaj:** Aynı gün içinde belirlenen eşiği (örn: 3 Ads tıklaması) aşan IP'leri otomatik sahte/şüpheli işaretler ve engeller.
- **Gelişmiş Raporlama & CSV Çıktısı:** 14 günlük görsel grafikler, günlük IP listesi, canlı arama ve Excel uyumlu UTF-8 CSV dışa aktarma.
- **Sıfır Bağımlılık (Zero-Config):** MySQL veritabanı kurmanıza gerek yoktur, dahili SQLite ile kurulum gerektirmeden dosya atıldığı anda çalışır.

---

## 🚀 Hızlı Kurulum

### 1. Dosyaları Sunucuya Yükleme
`adstracker_pro.zip` arşivini cPanel, Plesk veya VPS sunucunuzda web kök dizinine (örneğin `public_html` veya `tracker.alanadiniz.com`) yükleyin ve çıkartın.

### 2. Giriş Yapma
Tarayıcınızdan doğrudan alan adınıza gidin:
```
https://tracker.alanadiniz.com/
```
* **Varsayılan Kullanıcı Adı:** `admin`
* **Varsayılan Şifre:** `admin`

*(Panele girdikten sonra sağ üstteki **Ayarlar** sekmesinden hem kullanıcı adınızı hem de şifrenizi dilediğiniz zaman değiştirebilirsiniz).*

---

## 🔌 Hedef Sitelere Entegrasyon

Panelinizdeki **"➕ Yeni Site Ekle"** butonuna basarak sitenizi ekleyin. Sistem o siteye özel tekil bir `site_key` üretecektir.

### Seçenek A: JavaScript Etiketi (En Kolay Yöntem)
Hedef sitenizin `<head>` etiketleri arasına panelden aldığınız kodu ekleyin:
```html
<!-- AdsTracker Pro Koruma Kodu -->
<script src="https://tracker.alanadiniz.com/api/tracker.js" data-site-key="BURAYA_SITE_KEY" async></script>
```

### Seçenek B: PHP Shield Dosyası (Sunucu Seviyesinde Tam Engel)
1. Paneldeki "Kodları Al" sekmesinden siteye özel oluşturulan `shield-client.php` dosyasını indirin ve hedef sitenizin ana dizinine atın.
2. Sitenizin `index.php` veya `wp-config.php` dosyasının en başına şu satırı ekleyin:
```php
<?php require_once __DIR__ . '/shield-client.php'; ?>
```

---

## 🎯 Google Ads Kampanyasında Sahte IP'leri Negatifleme

1. AdsTracker Pro ana sayfasındaki **"Tüm Şüpheli IP'leri Kopyala"** butonuna basın.
2. [Google Ads](https://ads.google.com) hesabınıza giriş yapın.
3. İlgili Kampanya > **Ayarlar** > **Ek Ayarlar** > **IP Adresi Dışlamaları** bölümünü açın.
4. Kopyaladığınız IP listesini yapıştırıp **Kaydet** butonuna basın.

---

## 📁 Dizin Yapısı

```
adstracker/
├── index.php             # Ana alan adı yönlendiricisi (Root entrypoint)
├── .htaccess             # Dizin yönlendirmesi & Güvenlik kuralları
├── .gitignore            # Git yoksayma kuralları
├── README.md             # Proje dokümantasyonu
├── AdsTracker_Pro_...    # Microsoft Word (.docx) detaylı kullanım kılavuzu
├── admin/                # Yönetici Paneli
│   ├── index.php         # Master çoklu site yönetim arayüzü
│   ├── api.php           # Admin AJAX ve CSV dışa aktarım motoru
│   ├── auth.php          # Oturum ve kimlik doğrulama katmanı
│   ├── login.php         # Giriş ekranı
│   └── logout.php        # Güvenli çıkış
├── api/                  # Hedef Site API & İstemcileri
│   ├── collect.php       # CORS destekli yüksek hızlı hit toplama endpoint'i
│   ├── tracker.js        # Evrensel asenkron JavaScript istemcisi
│   └── shield-client.php # PHP 403 Forbidden sunucu koruma istemcisi
├── data/                 # SQLite Veritabanı
│   ├── tracker.sqlite    # SQLite veritabanı dosyası
│   └── .htaccess         # Dışarıdan doğrudan erişimi engelleyen 403 kuralı
└── inc/                  # Çekirdek Kütüphaneler
    ├── db.php            # PDO SQLite bağlantı ve otomatik şema yöneticisi
    └── functions.php     # IP çözümleme, User-Agent ayrıştırma, Ads dedektörü
```

---

## 🔒 Güvenlik

- SQLite veritabanı klasörü (`/data/`), `.htaccess` kuralları ile tarayıcı üzerinden doğrudan indirilmeye karşı **403 Forbidden** ile korunmaktadır.
- Admin şifreleri tek yönlü `PASSWORD_DEFAULT` (Bcrypt) ile hash'lenerek saklanır.
- Cross-Site Scripting (XSS) ve SQL Injection ataklarına karşı PDO Prepared Statement ve strict sanitization uygulanmıştır.

---

## 📄 Lisans
Bu proje [MIT](LICENSE) lisansı ile lisanslanmıştır. Dilediğiniz gibi geliştirebilir, kendi projelerinizde veya müşterileriniz için kullanabilirsiniz.
