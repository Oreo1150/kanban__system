# การเพิ่มฟีเจอร์การ์ด (Card Feature) ลงในระบบ Kanban BOM

## สรุปการเปลี่ยนแปลง

### 1. ฐานข้อมูล (Database)
#### เพิ่มคอลัมน์ใหม่ในตาราฐานข้อมูล:
- **card_color** (VARCHAR(20)): เก็บสีของการ์ด (เช่น #3498db, #e74c3c)
- **quantity_per_card** (INT): เก็บจำนวนต่อการ์ด

### 2. หน้า BOM Admin (`pages/admin/bom.php`)
#### เพิ่มได้:
- **Color Picker**: สำหรับเลือกสีของการ์ดสำหรับแต่ละวัสดุ
- **Quantity per Card Input**: สำหรับระบุจำนวนต่อการ์ด
- **Card Preview**: แสดงตัวอย่างการ์ดสีที่เลือกพร้อมจำนวน

#### แก้ไขไฟล์:
- เพิ่มช่องกรอก color และ quantity_per_card ในส่วน Material Selector
- อัพเดท addMaterial() function เพื่อรวมข้อมูลการ์ด
- อัพเดท updateMaterialsList() เพื่อแสดงตัวอย่างการ์ดสี
- ปรับปรุง displayBOMDetails() เพื่อแสดงการ์ดในรายละเอียด BOM

### 3. BOM API (`api/bom.php`)
#### แก้ไขให้รองรับ:
- **CREATE**: บันทึก card_color และ quantity_per_card เมื่อสร้าง BOM ใหม่
- **UPDATE**: อัพเดทข้อมูลการ์ดเมื่อแก้ไข BOM
- **GET_MATERIALS**: ส่งข้อมูลการ์ดไปยัง Production เมื่อดึงวัสดุจาก BOM

### 4. Jobs API (`api/jobs.php`)
#### เพิ่มข้อมูลการ์ดใน:
- **Create Job**: เมื่อ Planner สร้าง Job ระบบจะส่งข้อมูลการ์ดจาก BOM ไปยัง Production

### 5. Production Material Requests (`pages/production/material-requests.php`)
#### เพิ่ม:
- **Card Column**: แสดงการ์ดสีกับจำนวนต่อการ์ดในตารางวัสดุ
- **Visual Learning**: Production staff สามารถเห็นการ์ดสำหรับการจัดเก็บสต็อก
- อัพเดท setupMaterialCheckboxes() เพื่อรักษาข้อมูลการ์ด

### 6. Store Material Request Details (`api/get_request_detail.php`)
#### เพิ่ม:
- **Card Column**: แสดงการ์ดสีและจำนวนต่อการ์ดในตารางรายละเอียด
- **Card Color Display**: ให้ Store staff เห็นการ์ดสำหรับการเบิกวัสดุถูกต้อง

## ขั้นตอนการทำงาน

### แอดมิน/Planner:
1. เข้าไป Manage BOM (หน้า Admin)
2. สร้าง BOM ใหม่หรือแก้ไข BOM เดิม
3. เมื่อเพิ่มวัสดุ:
   - เลือกสีสำหรับการ์ด (Color Picker)
   - ระบุจำนวนต่อการ์ด
   - จะเห็นตัวอย่างการ์ดสีขึ้นมา
4. บันทึก BOM

### Planner สร้าง Job:
1. สร้างงานการผลิต (Production Job)
2. ระบบจะดึงข้อมูลวัสดุจาก BOM พร้อมข้อมูลการ์ด

### Production เบิกวัสดุ:
1. เข้าหน้า Material Requests
2. เลือกงาน แล้วโหลดวัสดุจาก BOM
3. จะเห็นตารางวัสดุพร้อมแสดงการ์ดสี
4. ส่งคำขอเบิกวัสดุ

### Store เบิกวัสดุ:
1. เข้าหน้า Material Requests (Store)
2. ดูรายละเอียดคำขอเบิกวัสดุ
3. จะเห็นการ์ดสำหรับแต่ละวัสดุ (สีและจำนวน)
4. อนุมัติและจ่ายวัสดุตามการ์ด

## ความสามารถเพิ่มเติม

✅ **การ์ดสี**: ช่วยให้ Production และ Store เห็นการ์ดอย่างชัดเจน
✅ **จำนวนต่อการ์ด**: ระบบจะแสดงจำนวนที่ต้องติดลงในการ์ด
✅ **ประสิทธิภาพ**: ลดความผิดพลาดในการเบิกวัสดุ
✅ **การติดตามสต็อก**: ช่วยให้จัดการสต็อกได้ดีขึ้น

## ไฟล์ที่แก้ไข

1. `pages/admin/bom.php` - เพิ่ม UI สำหรับการ์ด
2. `api/bom.php` - บันทึกและดึงข้อมูลการ์ด
3. `api/jobs.php` - ส่งข้อมูลการ์ดไปยัง Production
4. `pages/production/material-requests.php` - แสดงการ์ด
5. `api/get_request_detail.php` - แสดงการ์ดในรายละเอียด
6. Database Migration - เพิ่มคอลัมน์ card_color และ quantity_per_card

## วิธีการใช้งานเดิมที่ยังคงใช้ได้

- BOM Management ยังคงทำงานเหมือนเดิม
- Material Requests ของ Production ยังคงทำงานเหมือนเดิม
- Store Management ยังคงทำงานเหมือนเดิม

ฟีเจอร์นี้เพิ่มความสามารถในการแสดงผลและการติดตาม โดยไม่ทำลายการทำงานเดิม!
