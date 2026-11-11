# Roadmap / لیست توسعه

Ideas and open work, not promises. Move an item to the changelog in `readme.txt` when it ships.
ایده‌ها و کارهای باز؛ تعهد نیستند. هر کدام که منتشر شد، به changelog در `readme.txt` بروید.

## Planned / برنامه‌ریزی‌شده

- **Ignore list for leftovers of removed plugins.** A box on the Scan tab where you list tables and options to skip (for example `wp_revslider_slides`), so they stop counting as usage. Today only known caches and logs (Yoast indexables, Titan scanner, Rank Math analysis, Simple History, …) are skipped.
  فهرست نادیده‌گرفتن برای مانده‌ی افزونه‌های حذف‌شده: کادری در تب اسکن برای جدول‌ها و گزینه‌هایی که شمرده نشوند (مثل RevSlider).
- **Multisite.** Uninstall cleanup only handles a single site; make it loop over the network, and test the scan per site.
  پشتیبانی از multisite: پاک‌سازی هنگام حذف افزونه برای همه‌ی سایت‌های شبکه، و تست اسکن هر سایت.

## For the WordPress.org directory / برای مخزن وردپرس

- Submit the ZIP at https://wordpress.org/plugins/developers/add/ and, once approved, upload `.wordpress-org/` to the SVN `assets` folder. / ثبت zip در مخزن و بعد از تأیید، بارگذاری `.wordpress-org/` در پوشه‌ی assets.
- Translations: the bundled Persian file is a fallback until translate.wordpress.org has one. / ترجمه‌ی فارسی در translate.wordpress.org.

## Testing / تست

- Run the suites on PHP 7.4 and 8.0 to 8.4 (only 8.2 so far). / اجرای تست‌ها روی PHP 7.4 و 8.0 تا 8.4 (فعلاً فقط 8.2).
- Automated UI tests (today the interface is checked by hand in a browser). / تست خودکار رابط (الان دستی در مرورگر چک می‌شود).

## Ideas, not decided / ایده‌های تصمیم‌نگرفته

- Merge (shipped in 0.11.0) for the references it refuses today: ACF/SCF gallery fields, Pods file fields (meta plus the `_pods_` list), Meta Box groups and clones, JetEngine id+URL values and repeaters, plugin block attributes, Elementor dynamic tags and V4 atomic images, comments, term descriptions, plugin tables; URLs of old sizes, WebP/AVIF copies and CDN URLs without the uploads path. Each needs its storage format proven, like the supported ones. / ادغام برای ارجاع‌هایی که فعلاً رد می‌شوند: گالری ACF، فیلد فایل Pods، گروه‌های Meta Box، مقادیر و تکرارشونده‌های JetEngine، ویژگی بلوک افزونه‌ها، تگ پویای المنتور و V4، دیدگاه‌ها، توضیح دسته‌ها، جدول افزونه‌ها؛ آدرس سایزهای قدیمی، WebP/AVIF و CDN.
- Merge every group in one go ("keep the most used copy everywhere"), with one preview for all. / ادغام همه‌ی گروه‌ها با یک پیش‌نمایش.
- Pick "all but one" copy in each duplicate group automatically. / انتخاب خودکار «همه به‌جز یکی» در هر گروه تکراری.
- Warn before selecting every copy of a duplicate group. / هشدار قبل از انتخاب همه‌ی نسخه‌های یک گروه تکراری.
