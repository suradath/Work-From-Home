# Work-From-Home
Work from home (WFH) "การทำงานจากที่บ้านตามนโยบายของรัฐบาล" เป็นรูปแบบการทำงานที่ข้าราชการไม่ต้องเดินทางไปหน่วยงาน แต่ปฏิบัติงานจากที่พักอาศัยหรือสถานที่อื่นแทน โดยใช้อุปกรณ์ดิจิทัล เช่น อินเทอร์เน็ต, โปรแกรมประชุมออนไลน์, หรือแชทในการทำงานร่วมกับทีมตามปกติ
# 🏫 School Work From Home & Attendance System
**ระบบลงชื่อปฏิบัติงานออนไลน์สำหรับสถานศึกษา (Web Application)**

ระบบลงชื่อปฏิบัติงานออนไลน์ (Check-in/Check-out) พัฒนาด้วย PHP และ MySQL ออกแบบมาให้ใช้งานง่าย (User-Friendly) ทันสมัยด้วย Modern Dashboard และรองรับการใช้งานบนทุกอุปกรณ์ (Responsive Design) เหมาะสำหรับครูและบุคลากรทางการศึกษาในการรายงานตัวปฏิบัติงาน ทั้งรูปแบบ Work From Home (WFH) และการออกปฏิบัติราชการ

![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)
![CodeIgniter](https://img.shields.io/badge/Framework-CodeIgniter-dd4814.svg)
![MySQL](https://img.shields.io/badge/MySQL-8.0-orange.svg)
![Bootstrap](https://img.shields.io/badge/UI-Modern%20Dashboard-brightgreen.svg)

---

## ✨ ความสามารถของระบบ (Features)

ระบบถูกแบ่งออกเป็น 2 ส่วนหลัก เพื่อการจัดการที่มีประสิทธิภาพ:

### 👤 1. ระบบผู้ใช้งาน (สำหรับเจ้าหน้าที่ / ครู)
* **Check-in / Check-out:** ลงชื่อเข้าและออกจากการปฏิบัติงาน พร้อมบันทึกวัน-เวลาอัตโนมัติ
* **Location Tagging:** ระบุสถานที่ปฏิบัติงานได้ชัดเจน (เช่น บ้าน / ออกปฏิบัติราชการ)
* **Task Reporting:** ฟอร์มสำหรับกรอกภารกิจหรือรายละเอียดงานที่ปฏิบัติในแต่ละวัน
* **Photo Proof:** รองรับการแนบรูปภาพหลักฐานการทำงานจากมือถือหรือคอมพิวเตอร์
* **GPS Tracking:** ดึงพิกัด GPS อัตโนมัติจากอุปกรณ์ที่ใช้งาน 
* **Personal History:** ดูประวัติการลงชื่อย้อนหลังของตนเองได้แบบ Real-time
* **Mobile Ready:** ออกแบบ UI ให้ใช้งานผ่านสมาร์ทโฟนได้อย่างสมบูรณ์

### 🛡️ 2. ระบบผู้ดูแลระบบ (Admin Panel)
* **Modern Dashboard:** หน้าจอสรุปสถิติรายวัน (จำนวนผู้ลงชื่อเข้างาน, WFH, ออกราชการ) ในรูปแบบ Card และ Graph
* **Comprehensive Reports:** * รายงานการลงชื่อเข้า-ออกรายวัน/รายเดือน
  * รายงานสรุป Work From Home
  * รายงานการออกปฏิบัติราชการ
* **Advanced Search:** ค้นหาข้อมูลการลงชื่อตาม ชื่อ-สกุล, วันที่ หรือหน่วยงาน
* **Export Data:** ส่งออกรายงานเป็นไฟล์ Excel (`.xlsx`) และ PDF
* **User Management:** เพิ่ม / แก้ไข / ลบ ข้อมูลผู้ใช้งาน และ **รองรับการนำเข้าข้อมูลครูด้วยไฟล์ Excel**

---
### 💻 เทคโนโลยีที่ใช้ (Tech Stack)
* **Frontend :** HTML5, CSS3, JavaScript
* **UI Framework:** Bootstrap 5, FontAwesome (Icons), Chart.js (Graphs)
* ** Backend:** PHP (CodeIgniter Framework)
* **Database:** MySQL
* **Libraries:**
  * PhpSpreadsheet สำหรับ Export/Import ข้อมูลด้วย Excel
  * mPDF สำหรับสร้างรายงาน PDF

## 🚀 การติดตั้ง (Installation)
1. Clone repository นี้ไปยังเครื่องเซิร์ฟเวอร์ของคุณ
```
git clone [https://github.com/suradath/Work-From-Home.git](https://github.com/suradath/Work-From-Home.git)

```

2. นำเข้าไฟล์ฐานข้อมูล application/database/schema.sql ไปยัง MySQL ของคุณ

3. คัดลอกไฟล์ application/app/config.php

4. ตั้งค่าการเชื่อมต่อฐานข้อมูลในไฟล์ config.php:

```
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'wfh_attendance',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
```

5. เข้าใช้งานระบบผ่าน Web Browser

## 📌 แผนการพัฒนาในอนาคต (Roadmap)
* [ ] ระบบอนุมัติการออกราชการโดยผู้บริหาร
* [ ] รองรับการสแกน QR Code เพื่อ Check-in

## 📜 License
โปรเจกต์นี้ใช้สัญญาอนุญาตแบบ MIT License
