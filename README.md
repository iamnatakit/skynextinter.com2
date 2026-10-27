# skynextinter.com

Website SKYNEXT INTER พัฒนาด้วย CodeIgniter 4 และ PHP มีระบบหน้าร้าน สมาชิก บทความ และเครื่องมือจัดการธุรกิจ

## เริ่มต้นใช้งาน

- PHP 8.2 ขึ้นไป พร้อมส่วนขยายตามที่ CodeIgniter 4 ต้องการ
- ติดตั้ง dependencies ด้วย `composer install`
- ติดตั้ง frontend tooling ด้วย `npm install` แล้ว build assets ด้วย `npm run build`
- คัดลอก `env` เป็น `.env` แล้วกำหนด `app.baseURL` และค่าที่เหมาะกับสภาพแวดล้อม
- ตั้ง document root ของเว็บเซิร์ฟเวอร์ให้ชี้ไปยังโฟลเดอร์ `public`

ส่วนติดต่อผู้ใช้ใช้ HTML5, CSS/Tailwind CSS, JavaScript, jQuery และ AJAX
ร่วมกับ PHP/CodeIgniter 4 โดย asset ของ Tailwind CSS และ jQuery ที่ build แล้ว
อยู่ใน `public/platform/` และไม่มีการโหลดไลบรารี frontend จาก CDN

ดูรายละเอียดการติดตั้งและการใช้งานเพิ่มเติมใน [PLATFORM.md](PLATFORM.md), [PLATFORM-MEMBERSHIP.md](PLATFORM-MEMBERSHIP.md) และ [WORKSPACE.md](WORKSPACE.md)

## การเก็บไฟล์และข้อมูล

ไฟล์ environment, ฐานข้อมูลและข้อมูล runtime, ไฟล์อัปโหลด และ snapshot/catalog ที่นำเข้าจากแหล่งภายนอกจะไม่รวมใน repository นี้ ตั้งค่าและเพิ่มเฉพาะ assets ที่คุณมีสิทธิ์เผยแพร่ในสภาพแวดล้อมปลายทาง

เว็บไซต์นี้ใช้ CodeIgniter 4 ดูข้อมูล framework ได้ที่ [เอกสาร CodeIgniter](https://codeigniter.com/user_guide/).
