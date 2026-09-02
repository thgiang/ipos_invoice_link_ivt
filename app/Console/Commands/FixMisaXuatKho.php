<?php

namespace App\Console\Commands;

use App\Services\MisaClient;
use Illuminate\Console\Command;

/**
 * Sửa số lượng nguyên liệu trên phiếu xuất kho MISA.
 *
 * Năm bước, đúng thứ tự trình duyệt làm: liệt kê → bỏ ghi sổ → đọc chi tiết →
 * ghi đè → ghi sổ lại. Xem `FixMisaXuatKho/builder.md` để biết từng bước cần gì.
 *
 * Mặc định **chạy thử**, không ghi gì lên MISA. Phải thêm `--apply` mới ghi thật,
 * và mọi giá trị cũ được lưu ra file trước khi ghi để còn khôi phục.
 */
class FixMisaXuatKho extends Command
{
    protected $signature = 'app:fix-misa-xuat-kho
                            {--start_date=2026-01-01 : Từ ngày (Y-m-d)}
                            {--end_date=2026-06-30 : Đến ngày (Y-m-d)}
                            {--fix : Chạy các bước sửa. Không có cờ này thì chỉ liệt kê}
                            {--apply : GHI THẬT lên MISA. Không có thì chỉ chạy thử}
                            {--refid= : Chỉ sửa đúng một phiếu (theo refid)}
                            {--refno= : Chỉ sửa đúng một phiếu (theo số chứng từ, vd C26MYA1)}
                            {--set=* : Đặt số lượng tuyệt đối, vd --set=NHAIXANH:5. Đè lên bảng hệ số}
                            {--only-set : Chỉ áp dụng --set, bỏ qua bảng hệ số cứng}
                            {--force : Bỏ qua câu hỏi xác nhận khi --apply}
                            {--limit=0 : Chỉ xử lý N phiếu đầu (0 = tất cả)}
                            {--backup= : File JSONL lưu giá trị cũ (mặc định storage/app/misa/backup_*.jsonl)}
                            {--page_size=1000 : Số phiếu mỗi trang khi liệt kê}
                            {--max_pages=200 : Chốt chặn số trang, tránh lặp vô hạn}
                            {--dump= : Ghi danh sách phiếu ra file JSON}
                            {--dump_payload= : Ghi payload bước 4 ra file JSON để đối chiếu}
                            {--done=* : File backup đã chạy (lặp lại được); phiếu đã sửa sẽ bỏ qua}
                            {--add-spoon : Phiếu nào chưa có THÌA thì thêm 1 dòng THIADAI hoặc THIANGAN}
                            {--no-multiply : Bỏ qua bảng hệ số, chỉ làm các việc khác (vd thêm thìa)}
                            {--origin= : File origin_thang1.json — nhân từ số lượng GỐC thay vì số hiện tại}
                            {--rules : Bật các luật đặc biệt (cốc/nắp/giấy nến/thìa)}
                            {--refids= : File danh sách refid (mỗi dòng một cái); chỉ xử lý các phiếu này}
                            {--no-random : Bỏ các luật xác suất (giấy nến 10%, đổi túi 10%) khi chạy sửa lại}
                            {--quota= : File JSON {mã: số lượng còn được chèn}; chèn +1 vào phiếu đủ lớn cho đến khi hết}
                            {--quota_min_qty=4 : Phải có sẵn ít nhất bao nhiêu mới được chèn thêm 1}
                            {--only-items= : Chỉ đụng vào các mã này (ngăn cách bằng dấu phẩy); mọi dòng khác giữ nguyên số hiện tại}
                            {--skip-broken= : File ghi refid phiếu lỗi. Có cờ này thì gặp lỗi ghi ra rồi chạy tiếp, và bỏ qua luôn các phiếu đã ghi trong file}';

    protected $description = 'Sửa số lượng nguyên liệu trên phiếu xuất kho MISA';

    /**
     * Mặt hàng cần tăng số lượng và hệ số nhân — khách hàng sẽ chỉnh lại sau.
     *
     * Ý nghĩa: số lượng mới = số lượng cũ × hệ số, làm tròn 1 chữ số thập phân.
     * Mã không có trong bảng này thì không đụng tới.
     *
     * @var array<string, float>
     */
    /**
     * Bang he so THANG 2 — sinh boi storage/app/misa/plan_month.php 2 1.5 2.5
     *
     * Quy tac khach hang chot: x1,50 la muc chuan; ma nao du nhieu den muc het
     * thang 6 van con e he thi cho tang nhe hon (toi da x2,50); khong ma nao
     * duoc vuot tran cua chinh no.
     *
     * Tran = phan con lai sau khi da de danh du cho cac thang sau theo NHU CAU
     * GOC cua chung. Moi thang tinh tran rieng khi toi luot — khong ap he so
     * thang nay len nhu cau thang sau.
     *
     * 25 ma bao bi khong co trong bang: chung di theo han muc chen (--quota).
     *
     * @var array<string, float>
     */
    public const ITEM_MULTIPLIERS = [
        'BOTCACAO' => 1.03,
        'BOTCONSOC' => 1.50,
        'BOTHOM' => 1.50,
        'BOTKHOAIMON' => 1.50,
        'BOTMATCHA' => 1.50,
        'BOTNANG' => 1.18,
        'BOTTHACH' => 1.50,
        'CAMVANG' => 1.50,
        'CAMXANH' => 1.50,
        'CAOSON' => 1.33,
        'CHANH' => 1.50,
        'COMTUOI' => 1.50,
        'COZYHOATAN' => 1.50,
        'COZYTUILOC' => 1.50,
        'CUKHOAIMON' => 1.50,
        'CUNANG' => 1.50,
        'DUONGDEN' => 1.50,
        'DUONGTRANG' => 1.50,
        'DUONGVANG' => 1.50,
        'GELATINEGELITA' => 1.50,
        'HATDE' => 1.50,
        'HATNOYENMACH' => 1.50,
        'HOPDAO' => 1.50,
        'HOPHATNOCUNANG' => 1.50,
        'HOPPHOMAI' => 1.14,
        'HOPPHOMAI2' => 1.33,
        'HUONGDUONGNGUYENBAN' => 1.50,
        'HUTOPPINGNHUA' => 1.50,
        'KIEUMACH' => 1.50,
        'LABACHA' => 1.50,
        'MAUTHUCPHAM' => 1.50,
        'MOCTE' => 1.02,
        'MUOISACH' => 1.50,
        'MUTBUOI' => 1.50,
        'MUTXOAI' => 1.50,
        'NHADAM' => 1.50,
        'NHAIXANH' => 1.50,
        'OLONGDAO' => 1.15,
        'QUABUOIDAXANH' => 1.50,
        'QUAMAN' => 1.50,
        'QUAMANGCUT' => 1.50,
        'QUAQUAT' => 1.50,
        'QUAXOAI' => 1.28,
        'RICHLUN' => 1.50,
        'SA' => 1.44,
        'SOCOLA_NGUYENCHAT' => 1.50,
        'SUADAC' => 1.21,
        'SUAGAUNESTLE' => 1.02,
        'SUATUOIDL' => 1.28,
        'SUATUOITH' => 1.50,
        'SYRUPBUOITRENDY' => 1.50,
        'SYRUPCAMSA' => 1.50,
        'SYRUPCARAMEL' => 1.50,
        'SYRUPDAO' => 1.12,
        'SYRUPHATDETORANI' => 1.50,
        'THACHDUA' => 1.06,
        'THITMANGCAU' => 1.50,
        'THUNGDUONG' => 1.18,
        'TINHDAUKHOAI' => 1.43,
        'TRADEN' => 1.50,
        'TRANCHAUDEN' => 1.50,
        'TRANCHAUTRANG' => 1.50,
        'TRANHAIGTB' => 1.50,
        'TUIBONGDOI' => 1.50,
        'TUIBONGDON' => 1.50,
        'TUIRAC' => 1.50,
        'VANI' => 1.50,
        'WHIPPINGAVONMORE' => 1.50,
        'WHIPPINGCREAM' => 1.50,
    ];

    /**
     * Chỉ sửa phiếu "Xuất kho sản xuất". Các loại khác (xuất tiêu dùng, trả
     * hàng) không dính tới định lượng chế biến nên để nguyên.
     */
    private const REFTYPE_SAN_XUAT = 2023;

    /**
     * COCTOPPING được cộng thêm khi phiếu có một trong các mã này.
     *
     * @var string[]
     */
    private const COCTOPPING_TRIGGERS = ['HATDE', 'CUKHOAIMON', 'TRANCHAUDEN'];

    /**
     * COCTOPPINGTO được cộng thêm khi phiếu có mã này.
     */
    private const COCTOPPINGTO_TRIGGER = 'WHIPPINGAVONMORE';

    /**
     * Cong them bao nhieu cai coc khi gap dieu kien.
     *
     * Khach hang xac nhan 2026-08-23: coc topping +2, coc topping to +1 — nguoc
     * voi con so ghi o cot "Quy tac" trong bang gui sang (1 va 2), nen giu day
     * la nguon duy nhat va doi chieu lai voi khach neu co gi la.
     */
    private const COCTOPPING_BONUS = 2;

    private const COCTOPPINGTO_BONUS = 1;

    /**
     * Nắp phải bằng đúng số cốc — xét sau cùng, sau mọi luật khác.
     *
     * Một loại nắp dùng chung cho nhiều cỡ cốc, nên vế phải là DANH SÁCH: số
     * nắp phải bằng TỔNG các cỡ cốc dùng nắp đó.
     *
     * Bảng này kiểm chứng được trên chính sổ live: ở tháng 3–6 (chưa lệnh nào
     * đụng tới) cả năm nhóm lệch đúng 0 ở mọi tháng. Trước đây bảng chỉ có hai
     * dòng topping nên ba nhóm còn lại bị thả nổi — tháng 1 lệch 468 cái nắp,
     * tháng 2 lệch 47 và 120, đều do lệnh sửa gây ra.
     *
     * @var array<string, array<int, string>>
     */
    private const LID_FOLLOWS_CUP = [
        'NAP' => ['COCM', 'COCL', 'COCCAFE'],
        'NAPMEO' => ['COCMMEO', 'COCLMEO'],
        'NAPCOCDEN' => ['COCMDEN', 'COCLDEN'],
        'NAPCOCMATCHA' => ['COCMATCHA'],
        'NAPTOPPING' => ['COCTOPPING'],
        'NAPTOPPINGTO' => ['COCTOPPINGTO'],
    ];

    /**
     * Xác suất cộng thêm 1 tờ giấy nến cho phiếu đã có sẵn giấy nến.
     */
    private const GIAYNEN_EXTRA_CHANCE = 10;

    /**
     * Doi tui 4 coc thanh 2 tui 2 coc, o 10% so phieu co tui 4 coc.
     *
     * Thang 1 khong nhap tui 4 coc nao ma so thue da ghi xuat 242/226 ton — am
     * 16 tui. Doi 10% sang tui 2 coc keo muc dung xuong con 0,9 lan, vua duoi
     * tran 0,93, dong thoi tieu bot cho tui 2 coc dang du. Mot tui 4 coc thay
     * bang hai tui 2 coc.
     *
     * So lieu mat hang lay tu dong that tren MISA, khong suy ra duoc cai nao.
     */
    private const SWAP_CHANCE = 10;

    private const SWAP_FROM = 'TUI4';

    private const SWAP_QTY_MULTIPLIER = 2;

    /**
     * @var array<string, mixed>
     */
    private const SWAP_TO = [
        'inventory_item_id' => '94ffbc6f-78ac-4b89-9e5a-e2ae8c4a0fee',
        'inventory_item_code' => 'TUI2',
        'inventory_item_name' => 'Túi giấy 2 cốc',
        'description' => 'Túi giấy 2 cốc',
        'unit_id' => '3a3d4460-f54b-46b5-a26a-cd416a020fa7',
        'unit_name' => 'Chiếc',
        'main_unit_id' => '3a3d4460-f54b-46b5-a26a-cd416a020fa7',
        'main_unit_name' => 'Chiếc',
        'main_convert_rate' => 1,
        'unit_price_finance' => 5500,
        'main_unit_price_finance' => 5500,
        'exchange_rate_operator' => '*',
        'inventory_item_type' => 0,
    ];

    /**
     * Thay hẳn mặt hàng này bằng mặt hàng khác, mọi phiếu, không quay xác suất.
     *
     * TRADEN2 (Trà đen Assam B) hết hàng từ tháng 3 nên xuất tiếp là âm kho,
     * trong khi TRADEN còn dư 211 kg. Khách chốt 2026-08-25: từ tháng 4 không
     * dùng TRADEN2 nữa, thay hết sang TRADEN.
     *
     * `he_so` là số gam TRADEN thay cho 1 gam TRADEN2 — CẢ HAI ĐỀU XUẤT THEO
     * GAM nên về mặt vật lý là 1:1; để 1,2 là cố ý tiêu thêm chỗ TRADEN đang
     * dư. Đừng lấy `main_convert_rate` (600 với 1000) ra quy đổi: đó là tỷ lệ
     * sang đơn vị chính để lên báo cáo tồn (túi, kg), không phải đơn vị ghi
     * trên dòng phiếu.
     *
     * Số liệu dòng lấy nguyên từ dòng thật trên MISA (TRADEN ở C26MYA6729) —
     * mọi id đều phải đúng, không suy ra được cái nào.
     *
     * @var array<string, array{he_so: float, dong: array<string, mixed>}>
     */
    private const ITEM_SUBSTITUTIONS = [
        'TRADEN2' => [
            'he_so' => 1.2,
            'dong' => [
                'inventory_item_id' => 'b4f45048-a3ec-4387-924b-8c0fc5d53f1e',
                'inventory_item_code' => 'TRADEN',
                'inventory_item_name' => 'Trà đen (500g/gói, 40 gói/thùng)',
                'description' => 'Trà đen (500g/gói, 40 gói/thùng)',
                'unit_id' => '230e614c-47ba-45d3-9b84-be8d3edc15a9',
                'unit_name' => 'g',
                'main_unit_id' => '5f620512-f192-4a81-b1ad-9dec864f6c72',
                'main_unit_name' => 'kg',
                'main_convert_rate' => 1000,
                'unit_price_finance' => 177.925,
                'main_unit_price_finance' => 177924.624,
                'exchange_rate_operator' => '/',
                'inventory_item_type' => 3,
            ],
        ],
    ];

    /**
     * Thìa: phiếu nào không có cả THIADAI lẫn THIANGAN thì thêm một dòng, chọn
     * ngẫu nhiên một trong hai.
     *
     * Số liệu lấy nguyên từ dòng thật trên MISA (THIANGAN ở C26MYB2, THIADAI ở
     * C26MYC98) — mọi id đều phải đúng, không suy ra được cái nào.
     *
     * @var array<string, array<string, mixed>>
     */
    private const SPOON_TEMPLATES = [
        'THIANGAN' => [
            'inventory_item_id' => '9c317e7a-3ba9-4868-a03a-0096c1561fdb',
            'inventory_item_name' => 'Thìa ngắn 16cm đen không bọc',
            'unit_id' => '71112249-98ce-4334-a06d-34736155fa35',
            'unit_name' => 'Cái',
            'main_unit_id' => '71112249-98ce-4334-a06d-34736155fa35',
            'main_unit_name' => 'Cái',
            'main_convert_rate' => 1,
            'unit_price_finance' => 110,
            'main_unit_price_finance' => 110,
        ],
        'THIADAI' => [
            'inventory_item_id' => '9812799a-6d70-4c9c-aa2a-f3900ab8eb60',
            'inventory_item_name' => 'Thìa dài 21cm đen không bọc',
            'unit_id' => '71112249-98ce-4334-a06d-34736155fa35',
            'unit_name' => 'Cái',
            'main_unit_id' => '71112249-98ce-4334-a06d-34736155fa35',
            'main_unit_name' => 'Cái',
            'main_convert_rate' => 1,
            'unit_price_finance' => 210,
            'main_unit_price_finance' => 210,
        ],
    ];

    /**
     * Số lẻ của số lượng: 1,5 g chứ không phải 1,55523 g.
     */
    private const QTY_DECIMALS = 1;

    /**
     * Mat hang chi dem duoc theo don vi nguyen — khong the xuat 1,5 cai tui.
     *
     * Day cung la nhom "bao bi": giu he so 1, khong nhan, chi duoc chen them
     * tung chiec mot theo han muc (--quota). Nhan he so cho chung vua vo nghia
     * vua vo tac dung — moi dong thuong dung 1 chiec, nhan 1,5 roi lam tron van
     * ra 1.
     *
     * TUIBONGDOI / TUIBONGDON / TUINILONG khong nam day: khach hang xac nhan ba
     * ma nay nhan he so binh thuong duoc. HOPPHOMAI / HOPDAO / COZYTUILOC cung
     * vay — nua mieng pho mai, nua phan la co that.
     *
     * @var string[]
     */
    public const INTEGER_ITEMS = [
        'COCCAFE', 'COCL', 'COCLDEN', 'COCLMEO', 'COCM', 'COCMATCHA', 'COCMDEN', 'COCMMEO', 'COCTOPPING',
        'NAP', 'NAPCOCDEN', 'NAPCOCMATCHA', 'NAPMEO', 'NAPTOPPING',
        'TUI2', 'TUI4',
        'THIADAI', 'THIANGAN', 'ONGNHUANHO', 'ONGNHUATO',
        'KHAYGIAY', 'KHAYGIAY4', 'GIAYNEN', 'THUNG8', 'DAYCOI',
        'KIEUMACH', 'HUONGDUONGNGUYENBAN',
    ];

    /**
     * Mat hang duoc lam tron 3 chu so thay vi 1.
     *
     * So luong moi dong cua chung qua nho — BOTKHOAIMON trung binh 0,052 g tren
     * 5.658 dong — nen lam tron 1 chu so day gan nhu moi dong tu 0,05 len 0,1,
     * bien he so 1,7 thanh 2,3 va lam am ton kho. Ba ma nay la toan bo cac ma
     * bi lech qua 0,05 vi ly do do (da doi chieu ca 87 ma co phat sinh).
     *
     * @var string[]
     */
    private const FINE_ROUNDING_ITEMS = ['BOTKHOAIMON', 'VANI', 'MAUTHUCPHAM'];

    private const FINE_QTY_DECIMALS = 3;

    /**
     * MISA lưu main_quantity với 3 chữ số thập phân (đã đối chiếu 45/45 dòng).
     */
    private const MAIN_QTY_DECIMALS = 3;

    /**
     * MISA đánh số cột thay vì đặt tên. Các id này lấy từ request thật của
     * trình duyệt — không suy ra được từ dữ liệu nên ghi lại đúng vai trò.
     */
    private const PROP_POSTED_DATE = 3654;   // Ngày hạch toán — vừa lọc vừa sắp xếp

    private const PROP_VOUCHER_DATE = 3972;  // Ngày chứng từ

    private const PROP_VOUCHER_NO = 4018;    // Số chứng từ

    private const PROP_FISCAL_YEAR = 4041;   // Năm tài chính

    /**
     * Múi giờ sổ sách: MISA nhận mốc thời gian theo UTC nhưng người dùng nhập
     * theo giờ Việt Nam, nên "01/01/2026" phải gửi đi là 31/12/2025T17:00Z.
     */
    private const TZ_OFFSET_HOURS = 7;

    /**
     * 16 cột bước 4 cần mà bước 3 không trả về. Đã kiểm: hằng số trên mọi dòng.
     *
     * @var array<string, mixed>
     */
    private const DETAIL_DEFAULTS = [
        'account_object_id' => null,
        'account_object_code' => null,
        'account_object_name' => null,
        'account_object_address' => null,
        'contract_detail_id' => null,
        'serial_text' => null,
        'serial_inward_list' => null,
        'serial_define_list' => null,
        'serial_text_tooltip' => null,
        'sa_voucher_detail_unit_id' => '00000000-0000-0000-0000-000000000000',
        'discount_type' => 0,
        'unit_price' => 0,
        'sale_price1' => 0,
        'is_calculated_cost_contract' => false,
        'is_unit_price_after_tax' => false,
        'state' => 2,
    ];

    /**
     * Các cột bước 3 trả về mà bước 4 không gửi.
     *
     * @var string[]
     */
    private const DETAIL_DROP = [
        'unit_list', 'main_quantity_remain', 'main_quantity_remain_contract',
        'in_assembly_refno', 'product_code',
    ];

    private string $backupPath = '';

    /**
     * refid => [ref_detail_id => số lượng gốc] trước mọi lần sửa.
     *
     * Chạy lại với hệ số khác thì phải nhân từ số gốc, không phải từ số đã
     * sửa — nhân chồng lên nhau thì sai số làm tròn cứ thế tích lại.
     *
     * @var array<string, array<string, float>>
     */
    private array $origin = [];

    /**
     * Đếm các trường hợp luật đặc biệt không áp được, để báo lại cuối lượt.
     *
     * @var array<string, int>
     */
    private array $ruleSkips = [];

    /**
     * Số lượng còn được chèn thêm cho từng mã bao bì: mã => còn lại.
     *
     * "Tát nước theo mưa": bao bì đếm theo chiếc nên không nhân hệ số được,
     * phải chèn từng cái một. Chỉ chèn vào phiếu đã có nhiều — thêm 1 cốc vào
     * phiếu 8 cốc là lệch 12%, nhưng thêm vào phiếu 1 cốc là lệch 100%.
     *
     * @var array<string, float>
     */
    private array $quota = [];

    /**
     * Đã chèn được bao nhiêu, để báo cuối lượt.
     *
     * @var array<string, int>
     */
    private array $quotaUsed = [];

    private string $quotaPath = '';

    /**
     * Chỉ những mã này được sửa; mọi dòng khác giữ nguyên số đang có.
     *
     * Dùng khi chỉ cần chữa vài mã bị âm tồn: chạy lại cả phiếu với bảng hệ số
     * sẽ kéo luôn những mã đang cố ý để cao về mức chuẩn, mất công sửa đã đành
     * còn làm sai ý người dùng.
     *
     * @var array<string, true>
     */
    private array $onlyItems = [];

    /** File ghi refid phiếu lỗi; rỗng nghĩa là gặp lỗi thì dừng như cũ. */
    /** Bao nhieu phieu loi lien tiep thi coi la hong ca he thong ma dung lai. */
    private const BROKEN_STREAK_LIMIT = 10;

    private string $brokenPath = '';

    /** @var array<string, true> */
    private array $brokenRefs = [];

    public function __construct(private readonly MisaClient $misa)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $startDate = (string) $this->option('start_date');
        $endDate = (string) $this->option('end_date');

        $this->info("=== MISA — phiếu xuất kho: {$startDate} .. {$endDate} ===");
        $this->line('  Mốc gửi đi : '.$this->utc($startDate).'  ..  '.$this->utc($endDate));

        $rows = $this->fetchList($startDate, $endDate);

        if ($rows === null) {
            return self::FAILURE;
        }

        if (! $this->option('fix')) {
            $this->report($rows);

            return self::SUCCESS;
        }

        return $this->fix($rows);
    }

    // ---------------------------------------------------------------- bước 1

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function fetchList(string $startDate, string $endDate): ?array
    {
        $pageSize = max(1, (int) $this->option('page_size'));
        $maxPages = max(1, (int) $this->option('max_pages'));
        $rows = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $response = $this->misa->outwardList($this->listPayload($startDate, $endDate, $page, $pageSize));

            if ($response === null) {
                $this->error('  Lỗi: '.$this->misa->lastError());

                return null;
            }

            $batch = $response['Data']['PageData'] ?? [];

            if (! is_array($batch) || $batch === []) {
                break;
            }

            $rows = array_merge($rows, $batch);
            $this->line(sprintf('  Trang %-3d: %4d phiếu (đã lấy %d)', $page, count($batch), count($rows)));

            if (count($batch) < $pageSize) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function listPayload(string $startDate, string $endDate, int $page, int $pageSize): array
    {
        return [
            'sort' => json_encode([
                ['property' => self::PROP_POSTED_DATE, 'desc' => true, 'data_type' => 3, 'operand' => 1],
                ['property' => self::PROP_VOUCHER_DATE, 'desc' => true, 'data_type' => 3, 'operand' => 1],
                ['property' => self::PROP_VOUCHER_NO, 'desc' => true, 'data_type' => 1, 'operand' => 1],
            ]),
            'filter' => [
                [
                    'property' => self::PROP_FISCAL_YEAR,
                    'value' => '[2020,2021,2022,2023,2024,2025,2026,2027,3030,3031]',
                    'operator' => 13, 'data_type' => 4, 'operand' => 1,
                ],
                ['property' => self::PROP_POSTED_DATE, 'value' => $this->utc($startDate), 'operator' => 10, 'operand' => 1, 'data_type' => 3],
                ['property' => self::PROP_POSTED_DATE, 'value' => $this->utc($endDate), 'operator' => 12, 'operand' => 1, 'data_type' => 3],
            ],
            'pageIndex' => $page,
            'pageSize' => $pageSize,
            'useSp' => false,
            'view' => 62,
            'summaryColumns' => [5042],
            'loadMode' => 2,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function report(array $rows): void
    {
        $this->newLine();
        $this->info('  Tổng cộng: '.count($rows).' phiếu.');

        if ($rows === []) {
            return;
        }

        $this->table(
            ['Số chứng từ', 'Ngày hạch toán', 'Loại', 'Diễn giải', 'Thành tiền', 'refid'],
            array_map(fn (array $r): array => [
                // Số chứng từ nằm ở refno_finance (in_outward_refno trùng hệt),
                // không phải refno như tên cột thường thấy ở MISA.
                (string) ($r['refno_finance'] ?? ''),
                mb_substr((string) ($r['posted_date'] ?? ''), 0, 10),
                (string) ($r['reftype_name'] ?? ''),
                (string) ($r['journal_memo'] ?? ''),
                number_format((float) ($r['total_amount'] ?? 0)),
                (string) ($r['refid'] ?? ''),
            ], array_slice($rows, 0, 10))
        );

        if ($this->option('dump')) {
            $path = (string) $this->option('dump');
            file_put_contents($path, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("  Đã ghi: {$path}");
        }
    }

    // ------------------------------------------------------------ bước 2..5

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function fix(array $rows): int
    {
        $targets = array_values(array_filter($rows, function (array $r): bool {
            if ((int) ($r['reftype'] ?? 0) !== self::REFTYPE_SAN_XUAT) {
                return false;
            }

            $refid = (string) $this->option('refid');
            $refno = (string) $this->option('refno');

            return ($refid === '' || (string) ($r['refid'] ?? '') === $refid)
                && ($refno === '' || (string) ($r['refno_finance'] ?? '') === $refno);
        }));

        // Nhân hệ số KHÔNG lặp lại được: chạy hai lần là ×1,3 thành ×1,69.
        // File backup của lần trước là danh sách phiếu đã đụng tới, dùng để chạy
        // tiếp sau khi dừng giữa chừng.
        $alreadyDone = $this->alreadyDone();

        if ($alreadyDone !== []) {
            $before = count($targets);
            $targets = array_values(array_filter(
                $targets,
                fn (array $r): bool => ! isset($alreadyDone[(string) ($r['refid'] ?? '')])
            ));
            $this->line('  Bỏ qua    : '.($before - count($targets)).' phiếu đã sửa ở lần chạy trước.');
        }

        // Sua lai mot nhom phieu cu the thi khong can quet ca thang.
        $wanted = $this->wantedRefids();

        if ($wanted !== []) {
            $targets = array_values(array_filter(
                $targets,
                fn (array $r): bool => isset($wanted[(string) ($r['refid'] ?? '')])
            ));
            $this->line('  Loc refid  : '.count($targets).' phiếu trong danh sách đưa vào.');
        }

        $limit = (int) $this->option('limit');

        if ($limit > 0) {
            $targets = array_slice($targets, 0, $limit);
        }

        $apply = (bool) $this->option('apply');
        $overrides = $this->overrides();
        $this->loadOrigin();
        $this->loadQuota();

        $only = trim((string) $this->option('only-items'));

        if ($only !== '') {
            foreach (explode(',', $only) as $code) {
                $code = trim($code);

                if ($code !== '') {
                    $this->onlyItems[$code] = true;
                }
            }

            $this->line('  Chỉ sửa: '.implode(', ', array_keys($this->onlyItems)).' — các dòng khác giữ nguyên.');
        }

        // Phiếu hỏng làm kẹt cả luồng: lệnh dừng ngay khi gặp lỗi, vòng lặp
        // ngoài chạy lại từ đầu rồi vấp đúng phiếu đó, lặp mãi. Ghi nó ra một
        // file rồi bỏ qua để luồng đi tiếp; rà soát và sửa riêng một lượt sau.
        $this->brokenPath = trim((string) $this->option('skip-broken'));

        if ($this->brokenPath !== '' && is_file($this->brokenPath)) {
            foreach (file($this->brokenPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $refid) {
                $this->brokenRefs[trim($refid)] = true;
            }

            $before = count($targets);
            $targets = array_values(array_filter(
                $targets,
                fn (array $row): bool => ! isset($this->brokenRefs[(string) ($row['refid'] ?? '')])
            ));

            if ($before > count($targets)) {
                $this->warn(sprintf('  Bỏ qua %d phiếu đã ghi trong %s', $before - count($targets), $this->brokenPath));
            }
        }
        $this->backupPath = (string) ($this->option('backup')
            ?: storage_path('app/misa/backup_'.date('Ymd_His').'.jsonl'));

        $this->newLine();
        $this->info('  Chế độ     : '.($apply ? 'GHI THẬT lên MISA' : 'CHẠY THỬ (không ghi gì)'));
        $this->line('  Phiếu xử lý: '.count($targets).' (reftype '.self::REFTYPE_SAN_XUAT.') trên tổng '.count($rows));
        $this->line('  Quy tắc    : '.$this->describeRules($overrides));
        $this->line('  Lưu bản cũ : '.$this->backupPath);

        if ($targets === []) {
            $this->warn('  Không có phiếu nào khớp.');

            return self::SUCCESS;
        }

        if ($apply && ! $this->option('force') && ! $this->confirm('  Ghi thật lên MISA '.count($targets).' phiếu. Tiếp tục?', false)) {
            return self::SUCCESS;
        }

        if (! is_dir(dirname($this->backupPath))) {
            mkdir(dirname($this->backupPath), 0755, true);
        }

        $done = $skipped = $failed = $inARow = 0;
        $single = count($targets) === 1;

        foreach ($targets as $row) {
            $result = $this->fixOne($row, $apply, $overrides, $single);
            $result === null ? $failed++ : ($result ? $done++ : $skipped++);

            if ($result === null) {
                if ($this->brokenPath === '') {
                    $this->error('  Dừng lại để không làm hỏng thêm phiếu nào.');
                    break;
                }

                $refid = (string) ($row['refid'] ?? '');

                if ($refid !== '' && ! isset($this->brokenRefs[$refid])) {
                    $this->brokenRefs[$refid] = true;
                    file_put_contents($this->brokenPath, $refid."\n", FILE_APPEND | LOCK_EX);
                }

                $this->warn('  Ghi vào danh sách phiếu lỗi, chạy tiếp.');

                // Lỗi liên tiếp là dấu hiệu hỏng cả hệ thống — token hết hạn,
                // MISA chặn — chứ không phải phiếu hỏng. Cứ chạy tiếp thì cả
                // nghìn phiếu lành bị ghi vào danh sách bỏ qua rồi không ai
                // ngó lại. Dừng để người chạy xử lý gốc rễ.
                if (++$inARow >= self::BROKEN_STREAK_LIMIT) {
                    $this->error(sprintf('  %d phiếu lỗi liên tiếp — nhiều khả năng hỏng cả hệ thống, không phải lỗi từng phiếu. Dừng lại.',
                        $inARow));
                    break;
                }

                continue;
            }

            $inARow = 0;
        }

        $this->newLine();
        $this->info(sprintf('  Đã sửa %d · bỏ qua %d · lỗi %d', $done, $skipped, $failed));

        if ($this->quotaUsed !== []) {
            arsort($this->quotaUsed);
            $this->newLine();
            $this->line('  Đã chèn thêm ('.number_format(array_sum($this->quotaUsed)).' đơn vị):');

            foreach ($this->quotaUsed as $code => $used) {
                $this->line(sprintf('    %-22s +%-6s còn lại %s', $code, number_format($used),
                    number_format($this->quota[$code] ?? 0)));
            }
        }

        foreach ($this->ruleSkips as $reason => $count) {
            $this->warn(sprintf('  Luật không áp được: %s — %d phiếu', $reason, $count));
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Nạp hạn mức chèn thêm, nếu được chỉ định.
     */
    private function loadQuota(): void
    {
        $this->quotaPath = (string) $this->option('quota');

        if ($this->quotaPath === '') {
            return;
        }

        if (! is_file($this->quotaPath)) {
            $this->warn("  Không thấy {$this->quotaPath} — bỏ qua luật chèn bao bì.");
            $this->quotaPath = '';

            return;
        }

        $this->quota = array_map('floatval', json_decode((string) file_get_contents($this->quotaPath), true) ?: []);
        $this->line('  Hạn mức chèn: '.count($this->quota).' mã, tổng '
            .number_format(array_sum($this->quota)).' đơn vị.');
    }

    /**
     * Ghi lại phần hạn mức còn dư.
     *
     * Ghi sau mỗi phiếu chứ không đợi cuối lượt: dừng giữa chừng thì lần chạy
     * sau vẫn biết còn bao nhiêu, không chèn thừa.
     */
    private function saveQuota(): void
    {
        if ($this->quotaPath === '') {
            return;
        }

        file_put_contents($this->quotaPath, json_encode($this->quota, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Danh sach refid duoc phep dung toi, neu co.
     *
     * @return array<string, true>
     */
    private function wantedRefids(): array
    {
        $path = (string) $this->option('refids');

        if ($path === '' || ! is_file($path)) {
            return [];
        }

        $ids = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '') {
                $ids[$line] = true;
            }
        }

        return $ids;
    }

    /**
     * Nạp bảng số lượng gốc, nếu được chỉ định.
     */
    private function loadOrigin(): void
    {
        $path = (string) $this->option('origin');

        if ($path === '') {
            return;
        }

        if (! is_file($path)) {
            $this->warn("  Không thấy {$path} — sẽ nhân từ số lượng hiện tại.");

            return;
        }

        $this->origin = json_decode((string) file_get_contents($path), true) ?: [];
        $this->line('  Số gốc    : '.number_format(count($this->origin)).' phiếu (nhân từ số gốc, không nhân chồng)');
    }

    /**
     * Số lượng tuyệt đối do người dùng chỉ định, đè lên bảng hệ số.
     *
     * @return array<string, float>
     */
    private function overrides(): array
    {
        $set = [];

        foreach ((array) $this->option('set') as $pair) {
            [$code, $value] = array_pad(explode(':', (string) $pair, 2), 2, null);

            if ($code !== null && $value !== null && is_numeric($value)) {
                $set[strtoupper(trim($code))] = round((float) $value, self::QTY_DECIMALS);
            }
        }

        return $set;
    }

    /**
     * @param  array<string, float>  $overrides
     */
    private function describeRules(array $overrides): string
    {
        $parts = [];

        foreach ($overrides as $code => $value) {
            $parts[] = $code.' = '.$value.' (đặt cứng)';
        }

        foreach ($this->option('only-set') ? [] : self::ITEM_MULTIPLIERS as $code => $factor) {
            if (! isset($overrides[$code])) {
                $parts[] = $code.' ×'.$factor;
            }
        }

        return $parts === [] ? '(không có mã nào)' : implode(' · ', $parts);
    }

    /**
     * Một phiếu: đọc chi tiết, tính lại, rồi (nếu --apply) bỏ ghi sổ → ghi đè →
     * ghi sổ lại.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, float>  $overrides
     * @return bool|null true đã sửa, false không có gì để sửa, null lỗi
     */
    private function fixOne(array $row, bool $apply, array $overrides, bool $verbose): ?bool
    {
        $refid = (string) $row['refid'];
        $refno = (string) ($row['refno_finance'] ?? '');

        $details = $this->misa->outwardDetails($refid);

        if ($details === null) {
            $this->reportFailure($refno, 'đọc chi tiết', null);

            return null;
        }

        // Số lượng mới của từng dòng, tính trước để còn áp các luật nhìn cả
        // phiếu (cốc → nắp, có hạt dẻ thì thêm cốc topping…).
        $targets = [];
        $codes = [];

        foreach ($details as $i => $detail) {
            $code = (string) ($detail['inventory_item_code'] ?? '');
            $codes[$code] = true;
            $base = $this->baseQuantity($refid, $detail);
            $targets[$i] = $this->newQuantity($code, $base, $overrides);
        }

        $swaps = [];

        if ($this->option('rules')) {
            $this->applyRules($details, $targets, $codes, $swaps);
        }

        $changes = [];
        $lines = [];

        foreach ($details as $i => $detail) {
            $code = (string) ($detail['inventory_item_code'] ?? '');
            $before = (float) ($detail['quantity'] ?? 0);
            $after = $targets[$i];
            $line = $this->rebuildLine($detail, $after);

            if (isset($swaps[$i])) {
                $line = array_merge($line, $swaps[$i]);
                // Doi mat hang thi tien phai tinh lai theo don gia moi.
                $line['main_quantity'] = round($after / (float) $line['main_convert_rate'], self::MAIN_QTY_DECIMALS);
                $line['amount_finance'] = round($line['main_quantity'] * (float) $line['main_unit_price_finance']);
                $code = (string) $line['inventory_item_code'];
            }

            $lines[] = $line;

            if ($after !== $before || isset($swaps[$i])) {
                $changes[] = [
                    'item' => isset($swaps[$i])
                        ? (string) ($detail['inventory_item_code'] ?? '?').' -> '.$code
                        : $code,
                    'quantity' => ['cu' => $before, 'moi' => $after],
                    'main_quantity' => ['cu' => (float) ($detail['main_quantity'] ?? 0), 'moi' => $line['main_quantity']],
                    'amount_finance' => ['cu' => (float) ($detail['amount_finance'] ?? 0), 'moi' => $line['amount_finance']],
                ];
            }
        }

        $added = null;

        if ($this->option('add-spoon') && ! $this->hasSpoon($details)) {
            $added = $this->spoonLine($lines, $refid);

            if ($added === null) {
                $this->reportFailure($refno, 'không dựng được dòng thìa (phiếu không có dòng nào để sao chép)', null);

                return null;
            }

            $lines[] = $added;
            $changes[] = [
                'item' => $added['inventory_item_code'],
                'quantity' => ['cu' => 0.0, 'moi' => (float) $added['quantity']],
                'main_quantity' => ['cu' => 0.0, 'moi' => (float) $added['main_quantity']],
                'amount_finance' => ['cu' => 0.0, 'moi' => (float) $added['amount_finance']],
                'them_moi' => true,
            ];
        }

        if ($changes === []) {
            if ($verbose) {
                $this->warn("  [{$refno}] không có mã nào cần sửa.");
            }

            return false;
        }

        $master = $this->misa->outwardMaster($refid);

        if ($master === null) {
            $this->reportFailure($refno, 'đọc header', null);

            return null;
        }

        $totalBefore = (float) ($master['total_amount_finance'] ?? 0);
        $totalAfter = array_sum(array_column($lines, 'amount_finance'));

        // Ghi bản cũ TRƯỚC khi đụng vào MISA, và ghi ngay ra đĩa: hỏng giữa
        // chừng thì vẫn còn đủ dữ liệu để khôi phục.
        // applied ghi theo D_KIEN truoc, roi sua lai theo ket qua that o cuoi.
        // Ghi truoc vi neu hong giua chung thi van con day du gia tri cu.
        $this->backup([
            'refid' => $refid,
            'refno' => $refno,
            'posted_date' => (string) ($row['posted_date'] ?? ''),
            'applied' => false,
            'du_kien_ghi' => $apply,
            'total_amount_finance' => ['cu' => $totalBefore, 'moi' => $totalAfter],
            'changes' => $changes,
            'details_goc' => $details,
        ]);

        if ($verbose) {
            $this->newLine();
            $this->line("  [{$refno}] {$refid}");
            $this->table(
                ['Mã hàng', 'SL cũ', 'SL mới', 'main_qty cũ', 'main_qty mới', 'Tiền cũ', 'Tiền mới'],
                array_map(fn (array $c): array => [
                    $c['item'],
                    $c['quantity']['cu'], $c['quantity']['moi'],
                    $c['main_quantity']['cu'], $c['main_quantity']['moi'],
                    number_format($c['amount_finance']['cu']), number_format($c['amount_finance']['moi']),
                ], $changes)
            );
            $this->line('  Tổng tiền phiếu: '.number_format($totalBefore).' -> '.number_format($totalAfter));
        }

        if (! $apply) {
            return true;
        }

        // edit_version phải lấy từ DANH SÁCH chứ không phải header: GET
        // /in_outward/{refid} không trả cột này, gửi 0 là MISA báo "Chứng từ đã bị
        // thay đổi" và từ chối bỏ ghi sổ.
        $editVersion = (int) ($row['edit_version'] ?? 0);
        $branchId = (string) ($master['branch_id'] ?? '');

        if ($editVersion === 0) {
            $this->reportFailure($refno, 'thiếu edit_version trong danh sách', null);

            return null;
        }

        $unposted = $this->misa->unpost($refid, $editVersion, $branchId, $this->auditLog($refid, $refno, 'Bỏ ghi sổ'));

        if ($unposted === null) {
            $this->reportFailure($refno, 'bỏ ghi sổ', $refid);

            return null;
        }

        // Bỏ ghi sổ làm tăng edit_version của phiếu. Ghi đè bằng bản cũ thì MISA
        // trả "ObsoleteVersion" và phiếu nằm lại ở trạng thái chưa ghi sổ.
        $unpostedVersion = (int) ($unposted['Data']['edit_version'] ?? 0);

        if ($unpostedVersion === 0) {
            $this->reportFailure($refno, 'không lấy được edit_version sau khi bỏ ghi sổ', $refid);

            return null;
        }

        $payload = [$this->savePayload($master, $lines, $refid, $refno, $totalBefore, $totalAfter, $changes, $unpostedVersion)];

        if ($this->option('dump_payload')) {
            file_put_contents((string) $this->option('dump_payload'),
                json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        $saved = $this->misa->saveOutwardFull($payload);

        if ($saved === null) {
            $error = $this->misa->lastError();

            // Ghi đè hỏng thì phiếu vẫn đang chưa ghi sổ — trả nó về sổ ngay,
            // số liệu giữ nguyên như cũ.
            if ($this->rescue($refid, $refno, $branchId, (string) ($row['posted_date'] ?? ''))) {
                $this->newLine();
                $this->warn("  [{$refno}] ghi đè hỏng ({$error}) — đã ghi sổ lại, số liệu giữ nguyên. Bỏ qua phiếu này.");

                return false;
            }

            $this->reportFailure($refno, 'ghi đè (phiếu đang ở trạng thái CHƯA GHI SỔ)', $refid);

            return null;
        }

        // edit_version đổi sau mỗi lần ghi; dùng lại bản cũ là bị từ chối.
        // Phản hồi của bước ghi đè không có edit_version, nên đọc lại từ danh
        // sách — chỉ danh sách mới trả cột này.
        $newVersion = (int) (
            $saved['Data']['edit_version']
            ?? $saved['Data'][0]['edit_version']
            ?? $this->currentVersion($refid, (string) ($row['posted_date'] ?? ''))
        );

        if ($newVersion !== 0
            && $this->misa->post($refid, $newVersion, $branchId, $this->auditLog($refid, $refno, 'Ghi sổ')) !== null) {
            $this->confirmApplied($refid);
            $this->saveQuota();

            return true;
        }

        // Đến đây là phiếu đã sửa xong nhưng chưa ghi sổ — tức đang nằm ngoài sổ
        // sách. Không được bỏ mặc như vậy, phải cố ghi sổ lại cho bằng được.
        if ($this->rescue($refid, $refno, $branchId, (string) ($row['posted_date'] ?? ''))) {
            $this->newLine();
            $this->warn("  [{$refno}] ghi sổ lần đầu hỏng nhưng đã ghi sổ lại được — phiếu ổn.");
            $this->confirmApplied($refid);

            return true;
        }

        $this->reportFailure($refno, 'ghi sổ lại (phiếu ĐÃ SỬA nhưng CHƯA GHI SỔ)', $refid);

        return null;
    }

    /**
     * Mốc để nhân hệ số: số lượng gốc nếu có, không thì số hiện tại.
     *
     * @param  array<string, mixed>  $detail
     */
    private function baseQuantity(string $refid, array $detail): float
    {
        // Ở chế độ chỉ sửa vài mã, các dòng ngoài danh sách phải giữ nguyên số
        // ĐANG CÓ. Lấy số gốc cho chúng là âm thầm hoàn tác những lần nhân
        // trước đó, dù lần này không hề định đụng tới.
        if ($this->onlyItems !== [] && ! isset($this->onlyItems[(string) ($detail['inventory_item_code'] ?? '')])) {
            return (float) ($detail['quantity'] ?? 0);
        }

        $id = (string) ($detail['ref_detail_id'] ?? '');

        return (float) ($this->origin[$refid][$id] ?? $detail['quantity'] ?? 0);
    }

    /**
     * Các luật nhìn cả phiếu chứ không chỉ nhìn một dòng.
     *
     * @param  array<int, array<string, mixed>>  $details
     * @param  array<int, float>  $targets
     * @param  array<string, true>  $codes
     */
    private function applyRules(array $details, array &$targets, array $codes, array &$swaps): void
    {
        $indexOf = function (string $code) use ($details): array {
            $found = [];

            foreach ($details as $i => $detail) {
                if ((string) ($detail['inventory_item_code'] ?? '') === $code) {
                    $found[] = $i;
                }
            }

            return $found;
        };

        // --only-items phải chặn cả các luật, không chỉ bảng hệ số. Trước đây
        // luật ghi thẳng vào $targets nên chạy lại để nắn riêng số nắp cũng cộng
        // thêm cốc topping một lần nữa — không lặp lại được kết quả. Mọi luật
        // dưới đây ghi qua $put.
        $put = function (int $i, float $value) use ($details, &$targets): void {
            $code = (string) ($details[$i]['inventory_item_code'] ?? '');

            if ($this->onlyItems !== [] && ! isset($this->onlyItems[$code])) {
                return;
            }

            $targets[$i] = $value;
        };

        // Cốc topping: cộng thêm 2 khi phiếu có hạt dẻ / khoai môn / trân châu đen.
        if (array_intersect(self::COCTOPPING_TRIGGERS, array_keys($codes)) !== []) {
            foreach ($indexOf('COCTOPPING') as $i) {
                $put($i, $this->roundQuantity('COCTOPPING', $targets[$i] + self::COCTOPPING_BONUS));
            }
        }

        // Cốc topping to: cộng thêm 1 khi phiếu có whipping.
        if (isset($codes[self::COCTOPPINGTO_TRIGGER])) {
            foreach ($indexOf('COCTOPPINGTO') as $i) {
                $put($i, $this->roundQuantity('COCTOPPINGTO', $targets[$i] + self::COCTOPPINGTO_BONUS));
            }
        }

        // Cac luat xac suat chi duoc quay MOT LAN. Chay sua lai ma quay tiep thi
        // moi lan chay lai phieu se an them mot to giay nen, mot lan doi tui —
        // ket qua khong con lap lai duoc nua.
        $random = ! $this->option('no-random');

        // Giấy nến: phiếu đã có sẵn thì 10% số phiếu được cộng thêm 1 tờ.
        if ($random && isset($codes['GIAYNEN']) && random_int(1, 100) <= self::GIAYNEN_EXTRA_CHANCE) {
            foreach ($indexOf('GIAYNEN') as $i) {
                $put($i, $this->roundQuantity('GIAYNEN', $targets[$i] + 1));
                break;
            }
        }

        // 10% số phiếu có túi 4 cốc được đổi sang túi 2 cốc, số lượng nhân đôi.
        if ($random && isset($codes[self::SWAP_FROM]) && random_int(1, 100) <= self::SWAP_CHANCE) {
            foreach ($indexOf(self::SWAP_FROM) as $i) {
                $put($i, $this->roundQuantity(
                    (string) self::SWAP_TO['inventory_item_code'],
                    $targets[$i] * self::SWAP_QTY_MULTIPLIER
                ));
                $swaps[$i] = self::SWAP_TO;
            }
        }

        // Bao bì: chèn thêm 1 chiếc vào phiếu đã có sẵn đủ nhiều, chừng nào hạn
        // mức còn. Xét trước luật nắp để nắp kịp bám theo số cốc mới.
        if ($this->quota !== []) {
            $minQty = (float) $this->option('quota_min_qty');

            foreach ($details as $i => $detail) {
                $code = (string) ($detail['inventory_item_code'] ?? '');

                if (($this->quota[$code] ?? 0) < 1 || $targets[$i] < $minQty) {
                    continue;
                }

                $put($i, $this->roundQuantity($code, $targets[$i] + 1));
                $this->quota[$code]--;
                $this->quotaUsed[$code] = ($this->quotaUsed[$code] ?? 0) + 1;
            }
        }

        // Thay hẳn mặt hàng: xét trước luật nắp phòng khi sau này có mã cốc nào
        // bị thay, để nắp bám theo mã mới.
        foreach (self::ITEM_SUBSTITUTIONS as $from => $rule) {
            foreach ($indexOf($from) as $i) {
                $put($i, $this->roundQuantity((string) $rule['dong']['inventory_item_code'],
                    $targets[$i] * $rule['he_so']));

                if ($this->onlyItems === [] || isset($this->onlyItems[$from])) {
                    $swaps[$i] = $rule['dong'];
                }
            }
        }

        // Nắp phải bằng đúng số cốc — xét sau cùng, sau khi cốc đã chốt.
        foreach (self::LID_FOLLOWS_CUP as $lid => $cupCodes) {
            $lids = $indexOf($lid);
            $cups = [];

            foreach ($cupCodes as $cup) {
                foreach ($indexOf($cup) as $i) {
                    $cups[] = $i;
                }
            }

            if ($lids === [] || $cups === []) {
                continue;
            }

            $total = 0.0;

            foreach ($cups as $i) {
                $total += $targets[$i];
            }

            // Gan het vao dong dau, cac dong con lai ve 0 — tong moi la thu
            // phai bang so coc. Thang 1 khong co phieu nao nhieu hon mot dong
            // moi loai (da kiem 9.813 phieu), nen nhanh nay gan nhu khong chay.
            if (count($lids) > 1) {
                $this->ruleSkips[$lid.' (nhieu dong cung ma)'] = ($this->ruleSkips[$lid.' (nhieu dong cung ma)'] ?? 0) + 1;
            }

            foreach ($lids as $k => $i) {
                $put($i, $k === 0 ? $total : 0.0);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $details
     */
    private function hasSpoon(array $details): bool
    {
        foreach ($details as $detail) {
            if (isset(self::SPOON_TEMPLATES[(string) ($detail['inventory_item_code'] ?? '')])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Dòng thìa mới, sao phần “thuộc về phiếu này” (kho, đơn hàng, khoản mục chi
     * phí) từ một dòng sẵn có, phần “thuộc về mặt hàng” từ bản mẫu.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, mixed>|null
     */
    private function spoonLine(array $lines, string $refid): ?array
    {
        if ($lines === []) {
            return null;
        }

        // Tỉ lệ 2:1 nghiêng về thìa ngắn, theo yêu cầu của khách hàng.
        $code = random_int(1, 3) === 3 ? 'THIADAI' : 'THIANGAN';
        $template = self::SPOON_TEMPLATES[$code];
        $sample = $lines[0];

        $line = array_merge(self::DETAIL_DEFAULTS, $template, [
            'ref_detail_id' => (string) \Illuminate\Support\Str::uuid(),
            'refid' => $refid,
            'inventory_item_code' => $code,
            'description' => $template['inventory_item_name'],
            'quantity' => 1,
            'main_quantity' => 1,
            'amount_finance' => $template['unit_price_finance'],
            'amount_management' => 0,
            'unit_price_management' => 0,
            'main_unit_price_management' => 0,
            'sale_price' => 0,
            'sale_amount' => 0,
            'exchange_rate_operator' => '*',
            'debit_account' => '154',
            'credit_account' => '152',
            'inventory_item_type' => 3,
            'is_calculated_cost_order' => true,
            'inventory_item_cost_method' => -1,
            'is_follow_serial_number' => false,
            'is_allow_duplicate_serial_number' => true,
            'is_description' => false,
            'is_promotion' => false,
            'is_un_update_outward_price' => false,
            'un_resonable_cost' => false,
            'inventory_resale_type_id' => 0,
            'panel_length_quantity' => 0,
            'panel_width_quantity' => 0,
            'panel_height_quantity' => 0,
            'panel_radius_quantity' => 0,
            'panel_quantity' => 0,
            'quantity_product_produce' => 0,
            'edit_version' => 0,
            // Dòng thêm mới: state 1, và không có old_data vì trước đó chưa tồn tại.
            'state' => 1,
            'sort_order' => count($lines) + 1,
            // Những thứ gắn với phiếu chứ không gắn với mặt hàng.
            'stock_id' => $sample['stock_id'] ?? null,
            'stock_code' => $sample['stock_code'] ?? null,
            'stock_name' => $sample['stock_name'] ?? null,
            'order_id' => $sample['order_id'] ?? null,
            'order_code' => $sample['order_code'] ?? null,
            'expense_item_id' => $sample['expense_item_id'] ?? null,
            'expense_item_code' => $sample['expense_item_code'] ?? null,
            'expense_item_name' => $sample['expense_item_name'] ?? null,
        ]);

        unset($line['old_data']);

        return $line;
    }

    /**
     * @param  array<string, float>  $overrides
     */
    private function newQuantity(string $code, float $before, array $overrides): float
    {
        if (isset($overrides[$code])) {
            return $this->roundQuantity($code, $overrides[$code]);
        }

        if ($this->option('only-set') || $this->option('no-multiply')) {
            return $before;
        }

        if ($this->onlyItems !== [] && ! isset($this->onlyItems[$code])) {
            return $before;
        }

        if (isset(self::ITEM_MULTIPLIERS[$code])) {
            return $this->roundQuantity($code, $before * self::ITEM_MULTIPLIERS[$code]);
        }

        return $before;
    }

    /**
     * Lam tron 1 chu so thap phan, tru nhung mat hang chi dem duoc nguyen don
     * vi — 1,5 cai tui thi khong ai xuat duoc.
     */
    private function roundQuantity(string $code, float $quantity): float
    {
        if (in_array($code, self::INTEGER_ITEMS, true)) {
            return round($quantity);
        }

        if (in_array($code, self::FINE_ROUNDING_ITEMS, true)) {
            return round($quantity, self::FINE_QTY_DECIMALS);
        }

        return round($quantity, self::QTY_DECIMALS);
    }

    /**
     * Dựng một dòng đủ cột cho bước 4 từ 55 cột bước 3 trả về.
     *
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function rebuildLine(array $detail, float $quantity): array
    {
        $line = $detail;

        foreach (self::DETAIL_DROP as $unused) {
            unset($line[$unused]);
        }

        $line = array_merge(self::DETAIL_DEFAULTS, $line);

        // Hai cột này phải đi theo số lượng, không thì phiếu tự mâu thuẫn.
        //
        // main_convert_rate là số đơn vị dòng trong 1 đơn vị chính (1 Túi = 500 g),
        // nên là PHÉP CHIA chứ không phải nhân. Đã đối chiếu 45/45 dòng của
        // C26MYA1, làm tròn 3 chữ số giống MISA.
        $rate = (float) ($detail['main_convert_rate'] ?? 1);
        $mainQuantity = round($quantity / ($rate != 0.0 ? $rate : 1), self::MAIN_QTY_DECIMALS);

        // Tiền tính theo đơn giá của ĐƠN VỊ CHÍNH, không phải unit_price_finance:
        // đơn giá dòng đã bị làm tròn nên nhân lên lệch (THUNGDUONG ra 528 thay vì
        // 486). Công thức này khớp 45/45 dòng.
        $line['quantity'] = $quantity;
        $line['main_quantity'] = $mainQuantity;
        $line['amount_finance'] = round($mainQuantity * (float) ($detail['main_unit_price_finance'] ?? 0));
        $line['state'] = 2;

        // MISA đối chiếu từng cột với old_data để biết cái gì đã đổi. Thiếu khối
        // này thì bị từ chối, mà để sai giá trị cũ cũng bị từ chối — nên nó phải
        // là nguyên văn dòng vừa đọc về, kèm state 0 (chưa sửa).
        $line['old_data'] = $detail + ['state' => 0, 'account_object_code' => ''];

        return $line;
    }

    /**
     * @param  array<string, mixed>  $master
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int, array<string, mixed>>  $changes
     * @return array<string, mixed>
     */
    private function savePayload(array $master, array $lines, string $refid, string $refno, float $totalBefore, float $totalAfter, array $changes, int $editVersion): array
    {
        $ids = array_column($lines, 'ref_detail_id');

        if ($lines !== []) {
            // Chỉ dòng đầu mang cột này, và nó là danh sách id lặp lại hai lần
            // — sao đúng theo request của trình duyệt.
            $lines[0]['list_ref_detail_id'] = array_merge($ids, $ids);
        }

        $object = $this->masterObject($master, $totalAfter, $editVersion);
        $object['old_data'] = $this->oldMaster($object, $master, $totalBefore);

        $updated = array_values(array_filter($changes, fn (array $c): bool => ! ($c['them_moi'] ?? false)));
        $inserted = array_values(array_filter($changes, fn (array $c): bool => (bool) ($c['them_moi'] ?? false)));

        $descriptions = array_map(
            fn (array $c): string => sprintf('- <Mã hàng: %s> Số lượng: từ <%s> thành <%s>.',
                $c['item'],
                number_format($c['quantity']['cu'], 3, ',', '.'),
                number_format($c['quantity']['moi'], 3, ',', '.')),
            $updated
        );

        $insertions = array_map(
            fn (array $c): string => sprintf('- Thêm mới <Mã hàng: %s> Số lượng: <%s>.',
                $c['item'], number_format($c['quantity']['moi'], 3, ',', '.')),
            $inserted
        );

        $summary = sprintf('- Thành tiền: từ <%s> thành <%s>.',
            number_format($totalBefore, 0, ',', '.'), number_format($totalAfter, 0, ',', '.'));

        return [
            'Type' => 'in_outward',
            'Key' => $refid,
            // reftype của phiếu là 2023 nhưng RefType ở payload này là 2020 —
            // hai con số khác nhau, đừng đồng nhất.
            'RefType' => 2020,
            'RefTypeCategory' => 202,
            'View' => 'view_in_outward',
            'Details' => [[
                'Type' => 'in_outward_detail',
                'Alias' => 'detail',
                'View' => 'view_in_outward_detail',
                'UseRecover' => true,
                'Objects' => $lines,
            ]],
            // MISA đối chiếu số chứng từ qua khối này; thiếu nó thì trả
            // "InvalidRefno" dù refno_finance ở Object đã đúng.
            'Links' => [[
                'Type' => 'in_inward_outward_list',
                'RefType' => 2020,
                'Object' => $this->linkObject($object, $master, $lines, $totalBefore),
            ]],
            'enableAutoSave' => true,
            'Object' => $object,
            'auditing_log' => [
                'reftype' => self::REFTYPE_SAN_XUAT,
                'action' => 2,
                'action_name' => 'Sửa',
                'reference' => 'Số chứng từ: '.$refno,
                'description' => "1. Thông tin chung:\n".$summary."\n- Số dòng: ".count($lines)
                    ."\n2. Chi tiết:\n".implode("\n", array_merge($insertions, $descriptions)),
                'state' => 1,
                'object_name' => 'Xuất kho sản xuất',
                'branch_name' => (string) ($master['branch_name'] ?? 'CÔNG TY CỔ PHẦN ZANGTEE'),
                'index' => 3,
                'isAuditingLog' => true,
                'isMultiMaster' => true,
                'masterDescription' => [$summary],
                'detailDescription' => [
                    'insert' => $insertions, 'update' => $descriptions, 'delete' => [], 'custom' => [],
                ],
            ],
            'BypassValidate' => (object) [],
            'OptionForSave' => ['PostAfterSave' => true, 'IsQuickEdit' => false, 'FormState' => 'Edit'],
        ];
    }

    /**
     * Header cho bước 4, đúng 37 khóa như trình duyệt gửi.
     *
     * GET /in_outward/{refid} trả về 57 cột; gửi nguyên cụm đó thì MISA từ chối
     * (InvalidRefno), nên chỉ lấy đúng những cột cần. Ngày tháng đổi sang dạng
     * UTC giống bản mẫu thay vì giữ +07 như GET trả về.
     *
     * @param  array<string, mixed>  $master
     * @return array<string, mixed>
     */
    private function masterObject(array $master, float $totalAfter, int $editVersion): array
    {
        $utc = fn (?string $value): ?string => $value === null || $value === ''
            ? null
            : gmdate('Y-m-d\TH:i:s.v\Z', strtotime($value));

        return [
            'refid' => (string) $master['refid'],
            'journal_memo' => (string) ($master['journal_memo'] ?? 'Xuất kho sản xuất'),
            'branch_id' => (string) $master['branch_id'],
            'display_on_book' => (int) ($master['display_on_book'] ?? 0),
            'reftype' => self::REFTYPE_SAN_XUAT,
            'posted_date' => $utc($master['posted_date'] ?? null),
            'reforder' => $master['reforder'] ?? 0,
            'refdate' => $utc($master['refdate'] ?? null),
            'created_date' => $utc($master['created_date'] ?? null),
            'modified_date' => $utc($master['modified_date'] ?? null),
            'in_reforder' => $utc($master['in_reforder'] ?? null),
            // Đang ở giữa hai bước: vừa bỏ ghi sổ, chưa ghi lại.
            'is_posted_finance' => false,
            'is_posted_management' => (bool) ($master['is_posted_management'] ?? false),
            'is_posted_inventory_book_finance' => (bool) ($master['is_posted_inventory_book_finance'] ?? false),
            'is_posted_inventory_book_management' => (bool) ($master['is_posted_inventory_book_management'] ?? false),
            'is_branch_issued' => (bool) ($master['is_branch_issued'] ?? false),
            'is_sale_with_outward' => (bool) ($master['is_sale_with_outward'] ?? false),
            'is_invoice_replace' => (bool) ($master['is_invoice_replace'] ?? false),
            'total_amount_finance' => $totalAfter,
            'total_amount_management' => (float) ($master['total_amount_management'] ?? 0),
            'refno_finance' => (string) ($master['refno_finance'] ?? ''),
            'created_by' => (string) ($master['created_by'] ?? ''),
            'modified_by' => (string) ($master['modified_by'] ?? ''),
            'state' => 2,
            'edit_version' => $editVersion,
            'is_executed' => (bool) ($master['is_executed'] ?? false),
            'reftype_name' => (string) ($master['reftype_name'] ?? 'Xuất kho sản xuất'),
            'attachment_id_list_data' => [],
            'publish_status' => (int) ($master['publish_status'] ?? 0),
            'is_invoice_receipted' => (bool) ($master['is_invoice_receipted'] ?? false),
            'is_export_cancel' => (bool) ($master['is_export_cancel'] ?? false),
            'invoice_status' => (int) ($master['invoice_status'] ?? 0),
            'dav_using_permision' => (bool) ($master['dav_using_permision'] ?? true),
            'decreeType' => (int) ($master['decreeType'] ?? 1),
            'inv_replacement_id' => (string) ($master['inv_replacement_id'] ?? '00000000-0000-0000-0000-000000000000'),
            'lstContractRefid' => '',
            'lstContractRefidMaster' => '',
        ];
    }

    /**
     * Giá trị cũ của header: giống hệt $object nhưng giữ tổng tiền cũ, state 0,
     * và không có hai cột lstContract* (theo đúng bản mẫu).
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $master
     * @return array<string, mixed>
     */
    private function oldMaster(array $object, array $master, float $totalBefore): array
    {
        $old = $object;
        unset($old['lstContractRefid'], $old['lstContractRefidMaster']);

        $old['total_amount_finance'] = $totalBefore;
        $old['state'] = 0;
        $old['is_posted_finance'] = true;
        $old['status_sync_medicine_national'] = (int) ($master['status_sync_medicine_national'] ?? 3);

        return $old;
    }

    /**
     * Bản sao của header dành cho lưới danh sách, đúng 43 khóa như trình duyệt.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $master
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function linkObject(array $object, array $master, array $lines, float $totalBefore): array
    {
        // Danh sách kho xuất của phiếu, không trùng, nối bằng dấu phẩy.
        $stockIds = implode(',', array_values(array_unique(array_filter(
            array_column($lines, 'stock_id')
        ))));

        $link = [
            'refid' => $object['refid'],
            'branch_id' => $object['branch_id'],
            'payment_term_id' => (string) ($master['payment_term_id'] ?? '00000000-0000-0000-0000-000000000000'),
            'reftype' => self::REFTYPE_SAN_XUAT,
            'unit_price_method' => (int) ($master['unit_price_method'] ?? 1),
            'due_time' => (int) ($master['due_time'] ?? 0),
            'display_on_book' => $object['display_on_book'],
            'reforder' => $object['reforder'],
            'refdate' => $object['refdate'],
            'posted_date' => $object['posted_date'],
            'modified_date' => $object['modified_date'],
            'in_reforder' => $object['in_reforder'],
            'created_date' => $object['created_date'],
            'is_posted_finance' => false,
            'is_posted_management' => $object['is_posted_management'],
            'is_invoice_exported' => (bool) ($master['is_invoice_exported'] ?? false),
            'is_paid' => (bool) ($master['is_paid'] ?? false),
            'is_posted_cash_book_finance' => (bool) ($master['is_posted_cash_book_finance'] ?? false),
            'is_posted_cash_book_management' => (bool) ($master['is_posted_cash_book_management'] ?? false),
            'is_posted_inventory_book_finance' => $object['is_posted_inventory_book_finance'],
            'is_posted_inventory_book_management' => $object['is_posted_inventory_book_management'],
            'is_sale_with_outward' => $object['is_sale_with_outward'],
            'is_created_sa_return_last_year' => (bool) ($master['is_created_sa_return_last_year'] ?? false),
            'exchange_rate' => (float) ($master['exchange_rate'] ?? 1),
            'total_amount_finance' => $object['total_amount_finance'],
            'total_amount_management' => $object['total_amount_management'],
            'refno_finance' => $object['refno_finance'],
            'reftype_name' => $object['reftype_name'],
            'created_by' => $object['created_by'],
            'modified_by' => $object['modified_by'],
            'journal_memo' => $object['journal_memo'],
            'state' => 2,
            'edit_version' => $object['edit_version'],
            'sa_voucher_retype' => (int) ($master['sa_voucher_retype'] ?? 0),
            'sa_return_retype' => (int) ($master['sa_return_retype'] ?? 0),
            'publish_status' => $object['publish_status'],
            'send_email_status' => (int) ($master['send_email_status'] ?? 0),
            'is_invoice_receipted' => $object['is_invoice_receipted'],
            'invoice_status' => $object['invoice_status'],
            'publish_taxcode_status' => (int) ($master['publish_taxcode_status'] ?? 0),
            'ik_stock_ids' => $stockIds,
            'total_amount_oc' => (float) ($master['total_amount_oc'] ?? 0),
            'total_amount' => null,
            'MappingEinvoiceObjectList' => [],
        ];

        $link['old_data'] = $this->oldLink($link, $totalBefore);

        return $link;
    }

    /**
     * @param  array<string, mixed>  $link
     * @return array<string, mixed>
     */
    private function oldLink(array $link, float $totalBefore): array
    {
        $old = $link;
        unset($old['MappingEinvoiceObjectList']);

        $old['total_amount_finance'] = $totalBefore;
        $old['state'] = 0;
        $old['is_posted_finance'] = true;

        return $old;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditLog(string $refid, string $refno, string $action): array
    {
        return [
            'refid' => $refid,
            'action_name' => $action,
            'reference' => 'Số chứng từ: '.$refno,
            'object_name' => 'Xuất kho sản xuất',
            'branch_name' => 'CÔNG TY CỔ PHẦN ZANGTEE',
            'login_name' => 'Hoàng Giang Trần',
        ];
    }

    /**
     * Cố ghi sổ lại một phiếu đang treo, thử nhiều lần vì lần hỏng trước thường
     * chỉ là mạng chập.
     */
    private function rescue(string $refid, string $refno, string $branchId, string $postedDate): bool
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            usleep(1500 * 1000);

            $version = $this->currentVersion($refid, $postedDate);

            if ($version === 0) {
                continue;
            }

            if ($this->misa->post($refid, $version, $branchId, $this->auditLog($refid, $refno, 'Ghi sổ')) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * refid của những phiếu đã GHI THẬT ở lần chạy trước.
     *
     * @return array<string, true>
     */
    private function alreadyDone(): array
    {
        $done = [];

        foreach ((array) $this->option('done') as $path) {
            if (! is_string($path) || $path === '' || ! is_file($path)) {
                continue;
            }

            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $entry = json_decode($line, true);

                // Chỉ dòng có applied = true mới tính; dòng của lần chạy thử thì không.
                if (is_array($entry) && ($entry['applied'] ?? false) && isset($entry['refid'])) {
                    $done[(string) $entry['refid']] = true;
                }
            }
        }

        return $done;
    }

    /**
     * edit_version hiện tại của một phiếu, lấy qua danh sách của đúng ngày đó.
     */
    private function currentVersion(string $refid, string $postedDate): int
    {
        $day = mb_substr($postedDate, 0, 10);

        if ($day === '') {
            return 0;
        }

        $response = $this->misa->outwardList($this->listPayload($day, $day, 1, 1000));

        foreach ($response['Data']['PageData'] ?? [] as $candidate) {
            if ((string) ($candidate['refid'] ?? '') === $refid) {
                return (int) ($candidate['edit_version'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * Danh dấu một phiếu là đã sửa THÀNH CÔNG.
     *
     * `applied` phải phản ánh kết quả, không phải ý định: ghi sẵn true rồi gặp
     * lỗi thì lần chạy sau `--done` sẽ bỏ qua phiếu đó vĩnh viễn mà không ai
     * biết. Ghi thêm một dòng xác nhận thay vì sửa dòng cũ — file JSONL chỉ
     * nối thêm, và nhiều tiến trình cùng ghi một file.
     */
    private function confirmApplied(string $refid): void
    {
        $this->backup(['refid' => $refid, 'applied' => true, 'xac_nhan' => true]);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function backup(array $entry): void
    {
        file_put_contents(
            $this->backupPath,
            json_encode($entry, JSON_UNESCAPED_UNICODE).PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    private function reportFailure(string $refno, string $step, ?string $refid): void
    {
        $this->newLine();
        $this->error("  [{$refno}] hỏng ở bước {$step}: ".$this->misa->lastError());

        if ($refid !== null) {
            $this->warn("  refid cần xử lý tay: {$refid}");
        }
    }

    /**
     * 00:00 giờ Việt Nam của ngày đó, viết theo UTC — đúng dạng MISA gửi đi.
     */
    private function utc(string $date): string
    {
        // Neo thẳng vào +07 chứ không dựa vào timezone của PHP: máy chạy đặt
        // UTC hay +07 đều được, mốc gửi đi vẫn phải giống nhau.
        return gmdate('Y-m-d\TH:i:s.00\Z', strtotime($date.' 00:00:00 +0'.self::TZ_OFFSET_HOURS.':00'));
    }
}
