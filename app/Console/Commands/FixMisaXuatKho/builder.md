# Ghi chú kỹ thuật — 5 bước sửa phiếu xuất kho MISA

Rút ra từ các file `step_*.txt` (request thật của trình duyệt) cộng với vài lần dò
endpoint. Ghi lại vì không suy ra được từ dữ liệu.

## Thứ tự bắt buộc

| Bước | Method | Endpoint | Vai trò |
|---|---|---|---|
| 1 | POST | `in_inward_outward_list/paging_filter_v2` | Danh sách phiếu (chỉ có phần header) |
| 2 | DELETE | `ledger/v1/ledger/unpost` | **Bỏ ghi sổ** — phiếu đã ghi sổ thì không sửa được |
| 3 | POST | `in_outward/get_paging_detail` | Lấy các dòng hàng của phiếu |
| 4 | PUT | `in_outward/full` | Ghi đè cả phiếu (header + toàn bộ dòng) |
| 5 | POST | `ledger/v1/ledger/post` | **Ghi sổ lại** |

Hỏng giữa chừng là phiếu nằm ở trạng thái **chưa ghi sổ** — tức tạm thời ra ngoài
sổ sách. Command in ra `refid` của phiếu đang dở để còn xử lý tay.

## Bước 3 có cần không: CÓ

Bước 1 chỉ trả về header, không có dòng hàng nào; bước 4 lại phải gửi đủ mọi dòng.
Nên vẫn phải gọi bước 3.

**Nhưng phải bỏ tham số `columns`.** Request gốc liệt kê 12 cột của lưới và MISA
trả về đúng 12 cột đó — không đủ để ghi lại phiếu. Bỏ `columns` thì được **55 cột**.

## Bước 4 cần 67 cột mỗi dòng, bước 3 cho 55

16 cột còn thiếu đều là hằng số trên mọi dòng (đã kiểm trên cả 45 dòng của C26MYA1):

```
account_object_id/code/name/address = null      contract_detail_id          = null
serial_text/inward_list/define_list/tooltip = null   sa_voucher_detail_unit_id = "00000000-..."
discount_type = 0    unit_price = 0    sale_price1 = 0
is_calculated_cost_contract = false   is_unit_price_after_tax = false   state = 2
```

Cột thứ 17 là `list_ref_detail_id`: **chỉ có ở dòng đầu tiên**, và bằng danh sách
`ref_detail_id` của toàn bộ các dòng **lặp lại hai lần** (45 dòng → mảng 90 phần tử).

## Sửa số lượng thì đụng vào 3 cột

Bản mẫu sửa NHAIXANH từ 4g lên 6g:

```
quantity        4      -> 6         số lượng theo đơn vị của dòng (gam)
main_quantity   0.008  -> 0.012     = quantity × main_convert_rate
amount_finance  2815   -> 4222      = round(quantity × unit_price_finance)
```

Header phải cộng lại `total_amount_finance` = tổng `amount_finance` các dòng.

## Những chỗ dễ sai

- `edit_version` đổi sau **mỗi** lần ghi. Bước 5 phải dùng bản mới nhất do bước 4
  trả về, không dùng lại bản của bước 2.
- `allowOverOutwardStock: true` ở bước 5: tăng số lượng xuất có thể vượt tồn kho,
  không có cờ này MISA từ chối ghi sổ.
- MISA trả **HTTP 200 kèm `Success: false`** khi lỗi nghiệp vụ — chỉ nhìn mã HTTP
  là tưởng đã ghi xong.
- `reftype` của phiếu là **2023** (Xuất kho sản xuất) nhưng `RefType` trong payload
  bước 4 lại là **2020**, `RefTypeCategory` 202. Hai số khác nhau, đừng đồng nhất.
