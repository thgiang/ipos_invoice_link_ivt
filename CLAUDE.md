# CLAUDE.md — ipos_invoice_link_ivt

> Ghi chú đặc thù của project. Quy ước code chung (PHP/Laravel 12/Pest/Pint) xem `GEMINI.md`.

## Project này làm gì

Đây **không phải web app** — nó là một bộ **artisan command chạy tay** để đối soát dữ liệu giữa hai hệ thống của iPOS:

| Hệ thống | Domain | Vai trò |
|---|---|---|
| **Fabi** (POS/CMS) | `posapi.ipos.vn` | Đơn bán hàng + hóa đơn VAT |
| **IVT** (Inventory) | `apiivt.ipos.vn` | Phiếu xuất kho + công thức chế biến |

Mục tiêu: với mỗi hóa đơn VAT bên Fabi, tìm phiếu xuất kho tương ứng bên IVT, **bung công thức chế biến (recipe) xuống tận nguyên vật liệu thô**, rồi xuất file Excel để nộp/đối chiếu thuế. Đồng thời **dò lỗ hổng trong dãy số hóa đơn VAT**.

Web layer chỉ có hai màn hình chỉ-đọc để xem tổng hợp xuất/nhập (`/stock-out`, `/stock-in`, xem mục riêng bên dưới) — không có đăng nhập, không ghi dữ liệu. `InvoiceController` toàn method rỗng scaffold.

## Luồng chạy chuẩn

Xem `app/Console/Commands/CACH_CHAY.txt`. Thứ tự **bắt buộc** vì bước sau phụ thuộc dữ liệu bước trước:

```bash
php artisan sales:crawl --start_date=2026-06-20 --end_date=2026-06-30   # Fabi -> bảng sales
php artisan stock-outs:crawl --start_date=2026-06-20 --end_date=2026-06-30  # IVT -> bảng stock_outs (join sales qua tran_id)
php artisan stock-outs:get-detail                                        # lấy detail JSON cho stock_outs thiếu
php artisan app:build-excel-ivt-file 2026-06-01 2026-06-30               # xuất Excel + TXT
php artisan app:find-break-vat-invoice                                   # kiểm tra dãy số hóa đơn
```

Độc lập với luồng trên (chỉ để thống kê, không tham gia đối soát VAT):

```bash
php artisan stock-out-summary:crawl --start_date=2026-01-01 --end_date=2026-06-30
php artisan app:doi-soat-152      # đối soát sổ thuế TK 152 với IVT, cần bảng trên đã crawl
```

## Khóa nối dữ liệu: `tran_id`

`tran_id` là **business key xuyên suốt** cả 3 bảng. Cách lấy khác nhau ở mỗi nguồn:

- **Fabi**: trả thẳng trong response.
- **IVT**: phải **parse ra từ field `description`** (`CrawlStockOuts::extractTranId()`): cắt theo `__` lấy phần đầu, rồi strip prefix cửa hàng.
  ```
  FB_CHGIANGVANMINH_59BVR9MZ1ZJ5GS26ISMTCEKA__58000  ->  59BVR9MZ1ZJ5GS26ISMTCEKA
  ```
  ⚠️ Danh sách prefix cửa hàng nằm ở hằng `CrawlStockOuts::STORE_PREFIXES`. **Mở cửa hàng mới → phải thêm prefix vào đây**, nếu không `tran_id` sẽ sai và phiếu xuất không nối được với đơn bán.

## Tầng service

Mọi lời gọi HTTP đi qua hai client trong `app/Services/`, đăng ký singleton ở `AppServiceProvider` và inject vào command qua constructor:

- **`FabiClient`** — `login()`, `saleByDate()`, `vatInvoices()`
- **`IvtClient`** — `login()`, `stockOuts()`, `stockOutDetail()`, `recipes()`

Cả hai giữ token trong instance nên gọi `login()` một lần là đủ cho cả command (`GetStockOutDetail` gọi lại khi token hết hạn). Method trả `null` khi lỗi; lý do lấy qua `lastError()`. Header "vân tay trình duyệt" của IVT (`device-*`, `x-timezone`, ...) là hằng trong client — đó là contract của API, không phải config.

Thiếu credential trong `.env` → `RuntimeException` liệt kê đúng key nào thiếu, thay vì lỗi HTTP khó hiểu.

## Các bảng

| Bảng | Nguồn | Ghi chú |
|---|---|---|
| `sales` | Fabi `sale-by-date` + `vat-invoice` | Bảng chính hiện dùng. Key: `tran_id` (unique) |
| `stock_outs` | IVT `stock-out` | Key: `gi_id` (unique). Cột `detail` = longText chứa JSON list_item |
| `stock_out_summaries` | IVT `report/v1/inventory/stock-out-summary` | Báo cáo **tổng hợp** theo kỳ, xem mục riêng bên dưới |
| `invoices` | Fabi `vat-invoice` | **Bảng cũ, gần như bỏ** — `CrawlInvoices` không nằm trong luồng chạy chuẩn nữa |
| `users` | — | Chỉ là scaffold Laravel, không dùng |

Lịch sử migration đáng chú ý: `has_invoice` → đổi tên thành `has_sale`; thêm cột `loai_xuat` vào `stock_outs`.

## Những quy tắc nghiệp vụ đã "trả giá" mới có

Đây là phần dễ phá nhất khi sửa code — đều là các case thực tế đã gặp:

1. **Đơn 0 đồng** (`CrawlSaleByDate`): không lọc bằng `total_amount` (đây là giá **trước** giảm giá, luôn > 0). Phải xét `payment_method[0].amount == 0`. Đơn 0đ không phát sinh VAT → **xóa hẳn khỏi DB**.

2. **Số hóa đơn VAT phải pad 8 chữ số**: `24552` → `00024552` (`padVatInvoiceNumber()`). Command còn quét lại toàn bảng cuối mỗi lần chạy để pad các bản ghi cũ.

3. **API `sale-by-date` hay trả `vat_invoice_number` rỗng** dù `is_sync_vat = 1`. Fix: gọi thêm endpoint `vat-invoice` riêng, build map theo `tran_id`, rồi backfill các field VAT còn trống.

4. **Bung công thức đệ quy** (`BuildExcelIvtFile::congThuc()`): nguyên liệu của một công thức có thể lại là món có công thức riêng → đệ quy đến khi ra NVL thô. Có guard chống cycle (A → B → A).

5. **Quy đổi đơn vị**: bảng `$unitConversions` hardcode (vd `1 COC = 200 ML`, `1 PHAN = 40 GR`). Nếu đơn vị trong phiếu xuất khác đơn vị công thức mà **không có** trong bảng này → command `exit(1)` ngay, cố ý dừng để người dùng bổ sung.

6. **`$overwriteWarehouse`**: ép một số item về kho `BTRUNGLIET` bất kể kho gốc.

7. **`ky_hieu`** (ký hiệu hóa đơn) map cứng theo `store_uid` → `1C__NAM__MYA/B/C/E`, `__NAM__` thay bằng 2 số cuối của năm hóa đơn (`getKyHieu()`).

8. **`$ignores` trong `FindBreakVATInvoice`**: whitelist các số hóa đơn đã biết là "thủng" hợp lệ (đơn Shopee hủy nhưng trót xuất VAT, hóa đơn điều chỉnh, xuất gộp...). Danh sách này bồi đắp dần theo thời gian — đừng xóa.

## Tổng hợp xuất (`stock_out_summaries`)

`stock-out-summary:crawl` lấy báo cáo "Tổng hợp xuất" của IVT. Bốn đặc điểm chi phối toàn bộ thiết kế:

1. **Dòng dữ liệu không có ngày.** Đây là báo cáo cộng dồn: mỗi dòng là `item × kho` gộp cả kỳ. Dải 1 tháng cho ~594 dòng, dải 6 tháng cũng chỉ ~720 dòng — số dòng gần như không đổi, chỉ số lượng phình lên. Vì vậy bảng lưu `period_from` / `period_to`, và **cùng một item xuất hiện một lần cho mỗi kỳ**.

2. **Bắt buộc chia nhỏ theo tháng.** Server IVT có statement timeout riêng: dải 6 tháng trả HTTP 500 sau ~84s (lúc được lúc không), 3 tháng mất 7.7s, 1 tháng chỉ 2s. Command tự cắt khoảng ngày thành từng tháng dương lịch. Đừng gộp lại cho "gọn".

3. **Không được bỏ trống `from_warehouse_uid`.** Bỏ filter kho là API treo vô hạn. Danh sách 6 kho nằm ở hằng `CrawlStockOutSummary::WAREHOUSE_UIDS` — thêm kho mới phải bổ sung vào đây.

4. **Không có natural key tin cậy.** Report gom nhóm theo `product_uid` nhưng **không trả field này về**, nên cùng `(item_uid, kho, lot_no)` có thể ra 2 dòng chỉ khác `item_name` (ví dụ `TRADEN` → "Trà đen Assam B túi 600g" và "Trà đen GTB (túi 500g)"). Vì thế bảng **không có unique index** và command dùng **replace-by-period**: xóa sạch dòng của kỳ rồi insert lại trong một transaction. Chạy lại là idempotent; đừng đổi sang `updateOrCreate` vì sẽ nuốt mất dòng.

Các field `second_unit_*`, `lot_no`, `lot_date`, `discount_amount`, `second_unit_qty` hiện **luôn null hoặc 0** trên toàn bộ dữ liệu 2026 — vẫn giữ cột vì API có trả về.

Endpoint này không cần header `x-secret` / `x-timestamp` (đã kiểm chứng), dù trình duyệt có gửi.

## Đối soát sổ thuế TK 152 (`app:doi-soat-152`)

So sổ kế toán NVL (TK 152) với tổng xuất kho của **4 cửa hàng** trong `stock_out_summaries`.

**Bản chính thức của sổ thuế: `storage/Tong_hop_ton_kho - v0.4 - 2026.08.08.xlsx`, sheet `TỔNG HỢP TỒN KHO`** — thay cho `bao_cao_tai_chinh_6_thang_dau_nam_2026.xlsx` sheet `152` (bố cục cột y hệt, chỉ khác định giá). Tên file/sheet khai **một chỗ duy nhất** ở `TaxUnitCatalog::WORKBOOK` / `::SHEET`; cả command lẫn màn hình web đều lấy từ đó, nên khi kế toán gửi bản mới chỉ phải sửa một hằng số. Vẫn ép được bản khác bằng `--file` + `--sheet`.

Tên file xuất luôn là `doi_soat_152_*` bất kể sheet tên gì — kế toán đổi tên sheet mỗi lần gửi bản mới, file xuất mà đổi tên theo thì không so được giữa các lần chạy. Tên sheet nguồn nằm ở dòng "Nguồn sổ thuế" trong sheet Tổng hợp.

- Sheet 152 ghi tên kho là "Bếp Trung Liệt" nhưng **thực tế là tổng của 4 kho cửa hàng** — khách hàng xác nhận. Vì vậy phía IVT cộng đúng `CHLANGHA`, `CHGIANGVANMINH`, `CHLETRONGTAN`, `CHKHUCTHUADU`; loại Thái Hà và Trung Liệt để không đếm trùng hàng chỉ đi ngang qua.
- Cột dùng để đối soát là **O/P (Xuất kho — Số lượng / Giá trị)**. Cột M/N ("SL/Giá trị bán hàng") bằng 0 toàn bộ.
- Phía IVT dùng `amount_cost` (giá vốn) vì TK 152 ghi nhận giá trị NVL xuất kho theo giá vốn; cột `amount` vẫn xuất ra để tham chiếu.
### Quy đổi đơn vị — quy tắc quan trọng nhất

**Quy đổi đơn vị trong IVT luôn mang tính cục bộ theo mặt hàng.** Không được nói "1 túi = 1 kg"; chỉ được nói "1 túi *Bột giặt* = 1 kg". Cùng đơn vị TÚI nhưng mặt hàng A là 500g còn mặt hàng B là 900g.

`App\Services\UnitConversionCatalog` lấy bảng quy đổi từ IVT (`/api/main/v2/catalog/unit-conversion`, ~274 bản ghi) và cache JSON 24h ở `storage/app/ivt-cache/unit_conversions.json`.

- Mỗi bản ghi có `from_unit_id`, `to_unit_id`, `conversion_rate` và **`items[]`** — mảng này giới hạn phạm vi áp dụng. Ngữ nghĩa: `1 from_unit = conversion_rate × to_unit`. Bản ghi **không có `items`** bị bỏ qua có chủ đích vì nó không nói gì về mặt hàng cụ thể nào.
- Catalog dựng đồ thị đơn vị **riêng cho từng `item_id`**, tìm đường bằng BFS nên quy đổi nhiều bước vẫn ra (ví dụ `GR → CAI` qua một mắt xích trung gian).
- Ngoại lệ duy nhất được phép áp dụng toàn cục là tiền tố SI: `1 KG = 1000 GR`, `1 LIT = 1000 ML` (`METRIC_EDGES`).
- Nhãn đơn vị hai bên viết khác nhau (`Lọ`/`LO`, `Chiếc`/`CAI`, `g`/`GR`) được chuẩn hóa trong `normaliseUnit()` — đây chỉ là chuẩn hóa chính tả, **không hàm ý số lượng**.
- Mặt hàng mà catalog không tìm được đường quy đổi thì **phải hỏi khách hàng**, điền vào `DoiSoat152::ITEM_UNIT_FACTORS`. Chưa có thì rơi vào sheet "Chờ quy đổi" — tuyệt đối không bịa hệ số.
- `ITEM_UNIT_FACTORS` nhận hai dạng: số trần (`'X' => 2.5` — hiểu theo đơn vị IVT của chính dòng đó) hoặc `['qty' => 1000, 'unit' => 'ML']` (ghi đúng câu khách nói rồi tự quy sang đơn vị tồn kho qua `UnitConversionCatalog`). **Ưu tiên dạng thứ hai**: số trần sẽ sai âm thầm nếu IVT đổi đơn vị tồn của mặt hàng đó. Giải bằng `DoiSoat152::manualFactor()`, dùng chung cho cả command lẫn màn hình web.
- Đã chốt (khách xác nhận 2026-08-22), ghi theo đúng đơn vị khách nói rồi để catalog tự quy sang đơn vị IVT đang tồn:
  - `SUATUOITH` 1 Hộp = 1000 ML → IVT tồn theo LIT, hệ số 1
  - `MUOISACH` 1 Túi = 500 GR → IVT tồn theo KG, hệ số 0,5
  - `GELATINEGELITA` 1 Hộp = 1000 GR → IVT tồn theo KG, hệ số 1
  - `BOTTHACH` 1 Gói = 560 GR → IVT tồn theo TUI (1 TUI = 560 GR), hệ số vẫn 1 — sửa cách ghi chứ số không đổi
  - `BOTCONSOC` 1 Túi = 120 GR (1 hộp = 10 gói × 12 g) → IVT tồn theo GOI, **hệ số 10** — trước đây khai nhầm là 1
  - `MUTMAN` 1 Chai = 2100 GR → IVT tồn theo GR, hệ số 2100. Mã này vẫn nằm trong `TAX_DATA_ERRORS` (số liệu sổ thuế từng bị kết luận là sai) — có hệ số rồi thì nên hỏi lại khách xem còn giữ kết luận đó không.
- **Sổ thuế tách mã mà IVT chỉ có một** → khai ở `TaxUnitCatalog::ITEM_ALIASES` (IVT item_id => [tháng => mã sổ thuế]). Không suy được từ số liệu nên phải khai tay, và khai **theo từng tháng**. Màn hình hiện nhãn `mã 152: …` ở cột ĐVT 152 chứ không thay ngầm.
  - `TRADEN`: IVT ghi hết vào `TRADEN` (TUI); sổ thuế dùng `TRADEN2` (Túi) tháng 1–2, `TRADEN` (kg) tháng 3–6. **Bản tạm khách chốt 2026-08-22, sẽ sửa lại.** Lưu ý cả hai mã đều có số ở mọi tháng, map sang một mã thì mã kia bị bỏ — riêng T3 còn 24,2 triệu nằm ở `TRADEN2`.

### `gi_type` — chống đếm hai lần

`stock_out_summaries` tách theo `gi_type`; **tổng 6 loại khớp đúng con số khi gọi API không lọc**, đã kiểm chứng. Nhận diện qua tiền tố `gi_id`:

| gi_type | Mã | Ý nghĩa | 6 tháng đầu 2026 |
|---|---|---|---|
| 1 | XBH | Xuất bán hàng | 3.318.409.388 |
| 2 | XDC | Xuất điều chỉnh kho (kiểm kê) | 849.242.306 |
| 3 | XNB | **Xuất điều chuyển nội bộ** | **3.908.209.805** |
| 4 | XH | Xuất hủy | 100.876.693 |
| 5 | XK | Xuất nhân viên dùng | 62.410.750 |
| 6 | XSD | **Xuất sử dụng (chế biến)** | 2.011.763.332 |

**`gi_type = 3` phải bị loại khỏi mọi phép cộng tiêu hao.** Bếp chuyển sốt khoai ra quán là một lần xuất, quán bán tiếp là lần thứ hai — cùng một lô hàng. `DoiSoat152::GI_TYPE_TRANSFER` lo việc này, và giá trị bị loại được hiện thành cột riêng chứ không giấu đi.

**Chỗ đếm hai lần thứ hai — đã xử lý bằng cách lọc NVL thô.** `gi_type = 6` là NVL bị đốt để làm bán thành phẩm; bán thành phẩm đó sau lại xuất bán ở `gi_type = 1`. Chìa khóa là một sự thật đã kiểm chứng: **không mặt hàng nào trong 118 mã của sổ thuế có công thức** — TK 152 chỉ ghi NVL, bán thành phẩm nằm ở 154/155.

Nên `DoiSoat152::rawMaterialsOnly()` **loại mọi mã có công thức** khỏi phía IVT (`RecipeCatalog::processedItemIds()`). NVL của chúng đã được đếm đúng một lần ở `gi=6`. Không cần bung công thức. 30 mã bị loại, ~1,96 tỷ, liệt kê đầy đủ ở sheet "Bán thành phẩm đã loại" chứ không giấu.

Hiệu quả: chênh lệch tổng từ **+2,17 tỷ (+69%) xuống +238 triệu (+7,6%)**.

### Bếp chế biến — vì sao hai bên lệch mã hàng

Bếp Trung Liệt và Kho Thái Hà **chế biến** chứ không chỉ trung chuyển: NVL thô (`HATDE`, `CUKHOAIMON`, `SUATUOITH`...) được tiêu thụ tại Bếp để ra bán thành phẩm (`TP_SOTHATDE`, `COT_*`, `KHUCBACH*`), rồi mới chuyển ra 4 cửa hàng. Hệ quả:

- Mặt hàng "chỉ có ở sổ thuế" phần lớn là NVL thô dùng hết tại Bếp — **16/25 mặt hàng có phát sinh khi tính cả 6 kho**. Vì vậy báo cáo luôn kèm cột tham chiếu "cả 6 kho" (`readIvtAllWarehouses()`), đừng bỏ.
- Mặt hàng "chỉ có ở IVT" phần lớn là bán thành phẩm do Bếp làm ra.
- Muốn đối soát thật sự khớp thì phải **bung công thức** (`BuildExcelIvtFile::congThuc()`) để quy bán thành phẩm về NVL thô trước khi cộng.

`DoiSoat152::ITEM_NOTES` lưu các kết luận khách hàng đã chốt cho từng mã hàng (nghi ghi nhầm, chấp nhận vênh, giải thích hợp lệ) để lần sau không điều tra lại từ đầu.

## Giao diện xem tổng hợp (`/stock-out`, `/stock-in`)

Hai màn hình chỉ-đọc trên `stock_out_summaries` / `stock_in_summaries` — phần web duy nhất có nghiệp vụ. `SummaryReportController` + `resources/views/reports/summary.blade.php` (CSS inline, không cần `npm run build`).

Lọc: tích nhiều `gi_type`/`gr_type` + khoảng ngày + kho (bỏ trống = tất cả). Mặc định chọn mọi loại **trừ loại 3 (điều chuyển)** — đúng con số "đã xuất" mà project này dùng.

Bảng có **3 nhóm cột so sánh, mỗi nhóm 2 cột IVT / 152**: Số lượng, Đơn giá, Thành tiền — cộng cột Δ và Δ%. Phía 152 lấy cột **O/P** (Xuất kho SL/Giá trị) của workbook; đơn giá hai bên đều là **bình quân cả kỳ** (`giá trị ÷ số lượng`), không phải `main_unit_price`. Quy ước chênh lệch là **IVT − thuế**.

**Các cột "152" chỉ có nghĩa khi bộ lọc trùng cơ sở của sổ thuế**: kỳ đúng bằng kỳ ghi trên workbook, đủ 4 cửa hàng, không có loại 3. `SummaryReportController::basisMismatch()` kiểm và hiện băng vàng liệt kê từng chỗ lệch, kèm link một chạm đặt lại đúng cơ sở. Màn hình nhập kho **luôn** lệch cơ sở (cột O là hàng đi ra) nên luôn có cảnh báo.

Dòng TỔNG của nhóm "Thành tiền" **chỉ cộng phần giao nhau** giữa hai danh mục — cộng cả mã chỉ có ở IVT là tự tạo ra chênh lệch. Khi màn hình còn dùng bản v0.4 cả kỳ, đặt đúng cơ sở thì tổng trùng khít `app:doi-soat-152`: **IVT 3.320.806.467 đ / sổ thuế 2.659.278.682 đ trên 93 mã** (= 88 mã đối soát + 5 mã chờ quy đổi của command) — vẫn là phép kiểm tra tốt nhất khi sửa logic đối soát, chỉ cần trỏ `TaxUnitCatalog` về bản cả kỳ. Giờ màn hình đọc theo tháng nên con số hiển thị khác.

Ba điểm phải nhớ khi sửa:

1. **Chọn theo tháng, không phải khoảng ngày.** Dropdown Tháng 1..9/2026 (`FIRST_MONTH`/`LAST_MONTH`/`YEAR` trong controller). Dòng dữ liệu không có ngày và đã gộp sẵn theo tháng dương lịch (xem mục `stock_out_summaries`), nên tháng là đơn vị nhỏ nhất có nghĩa — đừng mở lại thành khoảng ngày tự do hay chia tỷ lệ theo ngày. Tháng chưa crawl / chưa có bảng 152 vẫn hiện trong dropdown kèm nhãn, không ẩn đi.
2. **Sổ thuế: một file cho mỗi tháng, đọc LIVE.** `storage/Bang_152_thang_{N}.xlsx` (`TaxUnitCatalog::MONTHLY_PATTERN`), sheet đầu tiên — lấy theo **index** chứ không theo tên, vì tên sheet không có gì đảm bảo. `TaxUnitCatalog::useMonth()` đổi nguồn và xoá sạch cache trong instance; **không có cache đĩa, không TTL** — kế toán sửa file xong F5 là thấy ngay (đã kiểm bằng cách sửa 1 ô rồi tải lại). Mỗi file ~110 dòng, đọc mất ~50ms nên không cần tối ưu. Màn hình hiện thời điểm file được lưu để đối chiếu.

   `TaxUnitCatalog::WORKBOOK`/`::SHEET` (bản v0.4 cả kỳ) **vẫn giữ** làm mặc định cho `app:doi-soat-152` — command đối soát cả 6 tháng một lần nên không dùng file tháng. Tên file tháng không có năm, nên năm chỉ biết được từ dòng "Tháng N năm YYYY" bên trong file; màn hình đối chiếu dòng đó với tháng đang chọn và báo nếu lệch (bắt trường hợp đặt sai tên file).

   ⚠️ Tổng 6 file tháng (3.132.327.355) **không bằng** bản v0.4 cả kỳ (3.128.252.563) — lệch 4.074.792. Hai bộ file là hai lần xuất khác nhau, đừng coi cái này là phân rã của cái kia.

3. **Hai cột đơn vị.** "SL gốc/ĐVT gốc" là `main_unit_qty`/`main_unit_id` thô. "SL quy đổi/ĐVT 152" quy về đơn vị sổ thuế qua `TaxUnitCatalog`, hệ số lấy từ `DoiSoat152::ITEM_UNIT_FACTORS` (đã đổi thành `public` để dùng chung) rồi mới đến `UnitConversionCatalog`. Mã không có trong sổ thuế hiện "không có ở 152"; có mà chưa có hệ số hiện "chờ quy đổi" — **không bịa hệ số**.
4. **Cột "Tỉ lệ" và cột "Đơn giá" — hai công thức khách hàng chỉ định.**
   - `152 cả tồn = cột Q + cột O` (SL cuối kỳ + SL xuất kho, file 152) — tách thành cột riêng để công thức Tỉ lệ đọc được bằng mắt.
   - `Tỉ lệ = SL 152 ÷ min(SL IVT đã quy đổi, 152 cả tồn)` — mẫu số lấy vế nhỏ hơn. Mọi vế cùng ĐVT 152 nên không thứ nguyên. Mẫu số bằng 0 thì để trống, không quy về 0 hay vô cực.
   - `Đơn giá = cột L ÷ cột K` (Giá trị nhập kho ÷ Số lượng nhập kho, file 152) — **đơn giá duy nhất trên toàn bảng, dùng cho cả hai bên**. Tháng nào không nhập thì **mượn giá của tháng gần nhất có nhập** (lùi về trước trước, hết mới sang tháng sau); giá mượn hiện nhãn `T…`. Chỉ mượn được khi tháng đó cùng ĐVT — `TaxUnitCatalog::resolvedInPrice()` kiểm điều này (TRADEN ghi theo Túi đầu năm, kg về sau).

   **Bảng giá của IVT không được dùng để định giá bất cứ chỗ nào** — khách hàng chốt 2026-08-22: chỉ tin số lượng của IVT, giá thì không. `Thành tiền IVT = SL IVT × đơn giá nhập 152`, `Thành tiền 152 = SL 152 × đơn giá nhập 152`, nên **chênh lệch thuần là chênh số lượng**, giá đã triệt tiêu. `amount_cost` chỉ còn dùng để xếp thứ tự các dòng không định giá được. Cột cuối `152 gốc (cột P)` giữ nguyên giá trị xuất của file — con số duy nhất tra ngược vào file được.

   ⚠️ Định giá lại khuếch đại lỗi dữ liệu: một ô sai trong file 152 nhân lên cả dòng. `price_odd` cảnh báo khi giá nhập lệch quá 3 lần so với **giá xuất của chính sổ thuế** (hai số này là bình quân gia quyền của cùng lô mua nên phải sát nhau). Đã bắt được: `DAUBIEC` T6 ghi nhập **8 g / 962.685đ = 120.336đ/g** trong khi các tháng khác 259–370đ/g → thổi dòng này từ ~1,9tr lên 437tr; `CHANH` T1 cũng bị gắn cờ.

5. **Tổng số lượng cộng ngang các đơn vị khác nhau** nên chỉ để tham khảo; con số so sánh được là tiền. Footer ghi rõ bao nhiêu mã quy đổi được.

6. **Bung công thức chế biến** (checkbox "Bung công thức", mặc định TẮT). `RecipeExploder` thay mỗi món có công thức bằng nguyên liệu thô, đệ quy, guard chống lặp vòng. Công thức IVT viết cho **1 đơn vị thành phẩm** nên `SL nguyên liệu = SL thành phẩm ÷ hệ số(đvt kho → đvt công thức) × định lượng`; **tiền chia theo tỷ trọng** `amount` của từng nguyên liệu trong công thức, nên **tổng tiền trước/sau khi bung bằng nhau đến từng đồng** — giá trong công thức chỉ là trọng số, không phải tiền. Nguyên liệu bung ra gộp vào dòng sẵn có (quy về đơn vị tồn kho của nó). Không quy đổi được thì **giữ nguyên, không bung**, và liệt kê ra khung cảnh báo.

   ⚠️ **Bung + tích loại 6 (XSD) = đếm trùng.** Cửa hàng tự đốt nguyên liệu làm topping — khoản đó đã nằm ở XSD (1.265.784.701 đ tại 4 cửa hàng, 6 tháng đầu 2026, chính là NHAIXANH/HOPPHOMAI/BOTSUA/TRADEN/SUADAC), bung thành phẩm bán ra ở loại 1 là cộng lần thứ hai. Màn hình phát hiện và cảnh báo đỏ kèm link bỏ loại 6. Bốn cách đọc trên đúng cơ sở sổ thuế:

   Đo trên **tháng 6/2026, 4 cửa hàng** (dùng `Bang_152_thang_6.xlsx`):

   | Cấu hình | IVT | Sổ thuế | Chênh |
   |---|---|---|---|
   | gi 1,2,4,5,6 — không bung (cách `app:doi-soat-152` dùng) | 608.665.182 | 522.088.423 | +16,6% (87 mã) |
   | gi 1,2,4,5,6 — có bung | 943.014.717 | 607.301.346 | **+55,3% — sai, đếm trùng** |
   | gi 1,2,4,5 — có bung | 747.022.324 | 607.301.346 | +23,0% (103 mã, nhiều nhất) |
   | gi 1,2,4,5 — không bung | 412.672.789 | 522.072.324 | −21,0% (thiếu NVL nằm trong thành phẩm) |

   Hệ số quy đổi đơn vị công thức lấy **hoàn toàn từ `UnitConversionCatalog`** — đã kiểm: catalog phủ đủ cả 11 mã lệch đơn vị đầu ra lẫn 29 nguyên liệu lệch đơn vị, không cần bảng hardcode. Lưu ý bảng `BuildExcelIvtFile::$unitConversions` **lệch với catalog**: nó ghi `1 COC COT_TRADAOCAMSA = 200 ML` còn IVT khai 180, và thiếu hẳn `TP_TRANCHAUCUNANG` (IVT: 1 PHAN = 60 GR).

7. **Không cột tiền nào được tính lại.** `Thành tiền` = `SUM(amount)`, `Giá vốn` = `SUM(amount_cost)` — hai cột này lưu nguyên xi giá trị IVT trả về, tiền do IVT tính trên từng chứng từ rồi tự cộng. **Đừng thay bằng `SUM(qty × price)`**: trên dữ liệu 2026, `SUM(main_unit_qty × main_unit_price)` lệch **0,19%** so với `SUM(amount)` (≈12% số dòng lệch, riêng 136 dòng xuất/216 dòng nhập có tiền nhưng `main_unit_qty = 0`) — vì một dòng báo cáo gộp nhiều chứng từ, mỗi chứng từ một đơn giá và một lần làm tròn, còn `main_unit_price` chỉ là đơn giá đại diện. Quan hệ đúng gần như tuyệt đối chỉ có `amount = amount_org` và `amount = sub_total - amount_vat`. `amount_cost` (giá vốn bình quân gia quyền) mới là cột dùng để đối soát TK 152, giống `DoiSoat152`.

Toàn bộ công thức trên được in ngay trên trang trong khối gấp "Công thức tính từng cột", kèm câu SQL phản ánh đúng bộ lọc đang chọn — sửa cách tính thì phải sửa cả khối đó.

`RecipeCatalog` và `UnitConversionCatalog` giờ **dùng lại cache cũ khi không gọi được IVT** thay vì ném exception, và báo cảnh báo (`staleWarning()`) — `app:doi-soat-152` in ra cuối lần chạy, màn hình web hiện ở khung vàng. Không có cache thì vẫn ném như cũ.

## Màn hình "Nhập nhiều — dùng ít" (`/ton-du`)

`TaxSurplusController` + `resources/views/reports/surplus.blade.php`. Cộng dồn nhiều file `Bang_152_thang_N.xlsx` (`TaxUnitCatalog::aggregate($from, $to)`), **chỉ dùng sổ thuế, không đụng IVT** — câu hỏi ở đây không phải "hai hệ thống có khớp không" mà "mua về mà không dùng hết bao nhiêu".

- `Dư = Σ Nhập − Σ Xuất`, `Đã dùng = Xuất ÷ Nhập`. Đầu kỳ lấy của tháng đầu, Tồn cuối kỳ lấy của tháng cuối — số dư, không phải tổng.
- **Tự kiểm `ĐK + Nhập − Xuất = CK` từng mã**, lệch thì gắn nhãn. Trên 6 file hiện có: 118 mã, **0 mã lệch, 0 mã đổi đơn vị** giữa các tháng.
- Mặc định xếp theo **số lượng dư** (khách yêu cầu) nhưng có cảnh báo: số lượng các mã đo bằng đơn vị khác nhau nên đầu bảng luôn là hàng tính theo gam. Có nút chuyển sang xếp theo **giá trị dư** — đó mới là câu trả lời cho "tiền đọng ở đâu". Dòng TỔNG chỉ cộng tiền.
- 6 tháng đầu 2026: nhập 4.016.966.380, xuất 3.132.327.355, **chênh 884.639.025**; tồn cuối kỳ theo sổ 1.775.476.443.

## Output

Tất cả đổ vào `storage/app/excel-ivt/` (đã gitignore):
- `{ky_hieu}/{YYYY-MM-DD}.xlsx` — chi tiết NVL theo ngày/cửa hàng
- `{ky_hieu}_tong_hop_{YYYY-MM}.xlsx` — tổng hợp NVL theo tháng
- `{ky_hieu}_thanh_toan_{YYYY-MM}.xlsx` — tổng hợp theo phương thức thanh toán
- `Dem_hoa_don_thue_{ky_hieu}.txt` — danh sách số hóa đơn, input cho `app:find-break-vat-invoice`
- `recipes_cache.json` — cache công thức từ IVT, TTL 24h

**Ghi file `.xlsx`**: trong **một lần chạy**, nhiều cửa hàng cùng `ky_hieu` ghi nối (append) vào chung một file — đây là cố ý. Nhưng lần ghi **đầu tiên của mỗi run** sẽ xóa file cũ còn sót từ run trước, nếu không chạy lại cùng dải ngày sẽ nhân đôi toàn bộ dòng. Cơ chế này nằm ở `BuildExcelIvtFile::$touchedFiles` + `writeToExcel()` — đừng bỏ khi refactor.

## Setup local

DB là **MariaDB/MySQL** tên `ivt` (XAMPP, user `root`, không mật khẩu). Không có `.env` trong repo:

```bash
cp .env.example .env
php artisan key:generate
mysql -u root -e "CREATE DATABASE ivt CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan migrate
```

Phải điền đủ vào `.env` thì crawl mới chạy: `FABI_EMAIL`, `FABI_PASSWORD`, `FABI_ACCESS_TOKEN`, `IVT_EMAIL`, `IVT_PASSWORD`, `IVT_ACCESS_TOKEN`, `IVT_DEVICE_ID`, `IVT_SECRET_KEY`. Không có giá trị fallback trong code — thiếu key nào là báo lỗi ngay tên key đó.

Seeder chỉ tạo 1 `Test User` — không liên quan nghiệp vụ, chạy hay không đều được.

## Điểm cần lưu ý / nợ kỹ thuật

- **Token cũ đã nằm trong git history**: `IVT_ACCESS_TOKEN`, `IVT_DEVICE_ID`, `IVT_SECRET_KEY` và `FABI_ACCESS_TOKEN` từng bị commit (trong `.env.example` và hardcode trong command). Code hiện đã sạch nhưng **history thì không** — nên xoay (rotate) các giá trị này.
- **Không có test nghiệp vụ**: `tests/` chỉ có 2 file `ExampleTest` mặc định. Các hàm thuần logic đáng test nhất: `extractTranId()`, `padVatInvoiceNumber()`, `congThuc()`, `getKyHieu()`.
- **`exit(1)` giữa command** (lỗi quy đổi đơn vị, cycle công thức) — cố ý dừng cứng, nhưng bỏ qua cleanup của Laravel.
- **`invoices:crawl` / bảng `invoices`** không còn nằm trong luồng chạy chuẩn. Giữ lại nhưng chưa rõ có còn dùng không.
- **`app:fix-created-at`** là command vá dữ liệu một lần, description còn để mặc định `'Command description'`.
