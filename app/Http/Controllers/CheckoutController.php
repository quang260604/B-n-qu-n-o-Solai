<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillHistory;
use App\Models\Cart;
use App\Models\Variant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Mail\OrderConfirmationMail;
use App\Models\Product;
use Illuminate\Support\Facades\Mail;

class CheckoutController extends Controller
{
public function processPayment(Request $request)
{
    $validated = $request->validate([
        'transaction_id' => 'required|string',
        'wallet_address' => 'required|string',
        'amount' => 'required|numeric',
        'recipient' => 'required|string',
        'bill_code' => 'required|string', // Mã đơn hàng
    ]);

    try {
        // Tìm đơn hàng từ bảng bills
        $bill = Bill::where('code', $validated['bill_code'])
                    ->where('payment_status', 'pending') // Đảm bảo đơn chưa được thanh toán
                    ->first();

        if (!$bill) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy đơn hàng!'], 404);
        }

        // // Lưu giao dịch vào bảng transactions
        // Transaction::create([
        //     'transaction_id' => $validated['transaction_id'],
        //     'wallet_address' => $validated['wallet_address'],
        //     'amount' => $validated['amount'],
        //     'recipient' => $validated['recipient'],
        //     'status' => 'completed',
        // ]);

        // Cập nhật trạng thái đơn hàng trong bảng bills
        $oldPaymentStatus = $bill->payment_status;
        $oldBillStatus = $bill->bill_status;

        $bill->update([
            'payment_status' => 'paid',
            'bill_status' => 'processing', // Hoặc trạng thái phù hợp với quy trình của bạn
        ]);

        // Lưu lịch sử vào bảng bill_histories
        BillHistory::create([
            'bill_id' => $bill->id,
            'by_user' => auth()->id(), // Người dùng thực hiện thanh toán
            'from_status' => $oldBillStatus,
            'to_status' => 'processing',
            'note' => 'Thanh toán thành công qua ví Phantom.',
            'at_datetime' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Thanh toán và cập nhật lịch sử thành công!']);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Có lỗi xảy ra trong quá trình xử lý.',
            'error' => $e->getMessage(),
        ], 500);
    }
}

    //
    public function checkout()
    {
        $user = Auth::user();
        $bills = Bill::where('user_id', $user->id)->get();

        // Lấy các mục giỏ hàng của người dùng từ database
        $cartItems = Cart::where('user_id', $user->id)->get();

        // Tính tổng giá của các sản phẩm trong giỏ hàng
        $total_price = $cartItems->sum(function ($item) {
            return $item->variants->sale_price * $item->variant_quantity;
        });

        // Lấy thông tin hóa đơn nếu cần
        $bills = Bill::where('user_id', $user->id)->get();

        // Trả về view kèm dữ liệu giỏ hàng và tổng giá
        return view('client.shop-checkout', [
            'cartItems' => $cartItems,
            'total_price' => $total_price,
            'user' => $user,
            'bills' => $bills
        ]);
    }

    public function processCheckout(Request $request)
    {
        // Kiểm tra nếu giỏ hàng trống
        $cartItems = Cart::where('user_id', Auth::id())->get();
        if ($cartItems->isEmpty()) {
            return redirect()->back()->withErrors(['error' => 'Giỏ hàng của bạn đang trống. Vui lòng thêm sản phẩm trước khi thanh toán.']);
        }

        $validateData = $request->validate([
            'full_name' => 'required|max:255',
            'phone_number' => 'required|regex:/^[0-9]{10}$/',
            'address' => 'required',
            'payment_type' => 'required|in:cod,online',
        ]);

        DB::beginTransaction();

        try {
            $user_id = Auth::id();
            $cartItems = Cart::where('user_id', $user_id)->get();

            // Kiểm tra hàng tồn kho
            $outOfStockItems = [];
            foreach ($cartItems as $item) {
                if ($item->variant_quantity > $item->variants->stock) {
                    $outOfStockItems[] = [
                        'product_name' => $item->variants->product->name,
                        'size' => $item->variants->size->name,
                        'available_stock' => $item->variants->stock,
                        'requested_quantity' => $item->variant_quantity,
                    ];
                }
            }

            if (!empty($outOfStockItems)) {
                // Tạo chuỗi thông báo lỗi chi tiết bao gồm tên sản phẩm và kích thước
                $errorMessages = [];
                foreach ($outOfStockItems as $outOfStock) {
                    $errorMessages[] = "{$outOfStock['product_name']} (Size: {$outOfStock['size']})";
                }

                return redirect()->route('tt-that-bai')->withErrors([
                    'error' => 'Một số sản phẩm không còn hàng: ' . implode(', ', $errorMessages)
                ]);
            }

            // Tính toán tổng giá
            $total_price = $cartItems->sum(function ($item) {
                return $item->variants->sale_price * $item->variant_quantity;
            });

            // Tạo mã hóa đơn
            $code = 'BILL-' . strtoupper(uniqid());

            // Tạo hóa đơn
            $bill = Bill::create([
                'user_id' => $user_id,
                'code' => $code,
                'full_name' => $validateData['full_name'],
                'phone_number' => $validateData['phone_number'],
                'address' => $validateData['address'],
                'payment_type' => 'cod',
                'total_price' => $total_price,
                'bill_status' => 'pending',
                'payment_status' => 'pending',
            ]);

            // Lưu mỗi sản phẩm trong hóa đơn
            foreach ($cartItems as $item) {
                BillItem::create([
                    'variant_id' => $item->variant_id,
                    'bill_id' => $bill->id,
                    'sale_price' => $item->variants->sale_price,
                    'listed_price' => $item->variants->listed_price,
                    'import_price' => $item->variants->import_price,
                    'variant_quantity' => $item->variant_quantity,
                    'product_name' => $item->variants->product->name,
                    'product_image_url' => $item->variants->product->primary_image_url,
                ]);

                // Giảm số lượng hàng tồn kho
                $item->variants->decrement('stock', $item->variant_quantity);
            }

            Cart::where('user_id', $user_id)->delete();

            DB::commit();

            // Gửi email xác nhận đơn hàng đến email của khách hàng
            // $userEmail = Auth::user()->email;
            // Mail::to($userEmail)->send(new OrderConfirmationMail($bill));

            return redirect()->route('tt-thanh-cong')->with('success', 'Thanh toán thành công!');
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Checkout error: ' . $e->getMessage());

            return redirect()->back()->withErrors(['error' => 'Có lỗi xảy ra trong quá trình đặt hàng. Vui lòng thử lại sau.']);
        }
    }

    public function processVNPAY(Request $request)
    {
        // Kiểm tra nếu giỏ hàng trống
        $cartItems = Cart::where('user_id', Auth::id())->get();
        if ($cartItems->isEmpty()) {
            return redirect()->back()->withErrors(['error' => 'Giỏ hàng của bạn đang trống. Vui lòng thêm sản phẩm trước khi thanh toán.']);
        }

        $validateData = $request->validate([
            'full_name' => 'required|max:255',
            'phone_number' => 'required|regex:/^[0-9]{10}$/',
            'address' => 'required',
            'payment_type' => 'required|in:cod,online',
        ]);

        // Lưu thông tin vào session
        session([
            'payment.full_name' => $validateData['full_name'],
            'payment.phone_number' => $validateData['phone_number'],
            'payment.address' => $validateData['address']
        ]);


          // Kiểm tra hàng tồn kho
          $outOfStockItems = [];
          foreach ($cartItems as $item) {
              if ($item->variant_quantity > $item->variants->stock) {
                  $outOfStockItems[] = [
                      'product_name' => $item->variants->product->name,
                      'size' => $item->variants->size->name,
                      'available_stock' => $item->variants->stock,
                      'requested_quantity' => $item->variant_quantity,
                  ];
              }
          }

          if (!empty($outOfStockItems)) {
              // Tạo chuỗi thông báo lỗi chi tiết bao gồm tên sản phẩm và kích thước
              $errorMessages = [];
              foreach ($outOfStockItems as $outOfStock) {
                  $errorMessages[] = "{$outOfStock['product_name']} (Size: {$outOfStock['size']})";
              }

              return redirect()->route('tt-that-bai')->withErrors([
                  'error' => 'Một số sản phẩm không còn hàng: ' . implode(', ', $errorMessages)
              ]);
          }

          
        // Tính tổng giá trị giỏ hàng
        $total_price = $cartItems->sum(function ($item) {
            return $item->variants->sale_price * $item->variant_quantity;
        });

        // Tạo mã đơn hàng duy nhất
        $code = 'BILL-' . strtoupper(uniqid());

        $vnp_TxnRef = $code;
        $vnp_Url = "https://sandbox.vnpayment.vn/paymentv2/vpcpay.html";
        $vnp_Returnurl = route('checkout.vnpay.returnFrom');
        $vnp_TmnCode = "KA1BV3N8";
        $vnp_HashSecret = "12GUKMUAGMQR4QW57D26MKG56RCYN9G8";

        $vnp_OrderInfo = "Thanh toán VNPAY - Mã đơn hàng: " . $vnp_TxnRef;
        $vnp_Amount = $total_price * 100;
        $vnp_Locale = "VN";
        $vnp_IpAddr = $_SERVER['REMOTE_ADDR'];

        // Chuẩn bị dữ liệu gửi đến VNPAY
        $inputData = [
            "vnp_Version" => "2.1.0",
            "vnp_TmnCode" => $vnp_TmnCode,
            "vnp_Amount" => $vnp_Amount,
            "vnp_Command" => "pay",
            "vnp_CreateDate" => date('YmdHis'),
            "vnp_CurrCode" => "VND",
            "vnp_IpAddr" => $vnp_IpAddr,
            "vnp_Locale" => $vnp_Locale,
            "vnp_OrderInfo" => $vnp_OrderInfo,
            "vnp_OrderType" => 'billpayment',
            "vnp_ReturnUrl" => $vnp_Returnurl,
            "vnp_TxnRef" => $vnp_TxnRef,
        ];

        ksort($inputData);
        $hashdata = http_build_query($inputData);
        $vnpSecureHash = hash_hmac('sha512', $hashdata, $vnp_HashSecret);
        $query = $hashdata . '&vnp_SecureHash=' . $vnpSecureHash;

        return redirect($vnp_Url . "?" . $query);
    }


    public function returnFromVNPAY(Request $request)
    {
        Log::info('VNPAY return response:', $request->all());

        $vnp_ResponseCode = $request->input('vnp_ResponseCode');
        $vnp_TxnRef = $request->input('vnp_TxnRef');
        $vnp_SecureHash = $request->input('vnp_SecureHash');

        $vnp_HashSecret = "12GUKMUAGMQR4QW57D26MKG56RCYN9G8";
        $inputData = $request->except('vnp_SecureHash');
        ksort($inputData);
        $hashdata = http_build_query($inputData);
        $secureHash = hash_hmac('sha512', $hashdata, $vnp_HashSecret);

        if ($vnp_ResponseCode == '00' && $secureHash == $vnp_SecureHash) {
            DB::beginTransaction();

            try {
                $user_id = Auth::id();
                $cartItems = Cart::where('user_id', $user_id)->get();

                $outOfStockItems = [];
                foreach ($cartItems as $item) {
                    if ($item->variant_quantity > $item->variants->stock) {
                        $outOfStockItems[] = [
                            'product_name' => $item->variants->product->name,
                            'size' => $item->variants->size->name,
                            'available_stock' => $item->variants->stock,
                            'requested_quantity' => $item->variant_quantity,
                        ];
                    }
                }

                if (!empty($outOfStockItems)) {
                    // Tạo chuỗi thông báo lỗi chi tiết bao gồm tên sản phẩm và kích thước
                    $errorMessages = [];
                    foreach ($outOfStockItems as $outOfStock) {
                        $errorMessages[] = "{$outOfStock['product_name']} (Size: {$outOfStock['size']})";
                    }

                    return redirect()->route('tt-that-bai')->withErrors([
                        'error' => 'Một số sản phẩm không còn hàng: ' . implode(', ', $errorMessages)
                    ]);
                }

                // Lấy lại thông tin từ session
                $full_name = session('payment.full_name');
                $phone_number = session('payment.phone_number');
                $address = session('payment.address');

                // Tính tổng giá trị giỏ hàng
                $total_price = $cartItems->sum(function ($item) {
                    return $item->variants->sale_price * $item->variant_quantity;
                });

                // Tạo hóa đơn
                $bill = Bill::create([
                    'user_id' => $user_id,
                    'code' => $vnp_TxnRef,
                    'full_name' => $full_name,
                    'phone_number' => $phone_number,
                    'address' => $address,
                    'payment_type' => 'online',
                    'total_price' => $total_price,
                    'bill_status' => 'pending',
                    'payment_status' => 'completed',
                ]);

                foreach ($cartItems as $item) {
                    BillItem::create([
                        'variant_id' => $item->variant_id,
                        'bill_id' => $bill->id,
                        'sale_price' => $item->variants->sale_price,
                        'listed_price' => $item->variants->listed_price,
                        'import_price' => $item->variants->import_price,
                        'variant_quantity' => $item->variant_quantity,
                        'product_name' => $item->variants->product->name,
                        'product_image_url' => $item->variants->product->primary_image_url,
                    ]);

                    $item->variants->decrement('stock', $item->variant_quantity);
                }

                Cart::where('user_id', $user_id)->delete();
                DB::commit();

                $userEmail = Auth::user()->email;

                return redirect()->route('tt-thanh-cong')->with('success', 'Thanh toán thành công!');
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Error saving bill after VNPAY success: ' . $e->getMessage());
                return redirect()->route('tt-that-bai')->withErrors(['error' => 'Không thể lưu hóa đơn. Vui lòng thử lại sau.']);
            }
        } else {
            return redirect()->route('tt-that-bai')->withErrors(['error' => 'Thanh toán không thành công.']);
        }
    }







    // mua ngay
    public function buyNow(Request $request)
    {
        if (!Auth::check()) {
            // Lưu thông báo vào session
            return redirect()->route('login')->with('error', 'Bạn cần đăng nhập để mua hàng.');
        }

        $user = Auth::user();

        // Lấy thông tin sản phẩm từ request
        $variantId = $request->input('variant_id');
        $quantity = $request->input('variant_quantity');

        // Kiểm tra nếu variantId và quantity tồn tại
        if (!$variantId || !$quantity) {
            return redirect()->back()->withErrors('Không có thông tin sản phẩm.');
        }

        // Tìm sản phẩm tương ứng
        $variant = Variant::findOrFail($variantId);
        $product = $variant->product;

        return view('client.buy-now', [
            'user' => $user,
            'product' => $product,
            'variant' => $variant,
            'quantity' => $quantity,
        ]);
    }



    // mua ngay 
    public function processBuyNow(Request $request)
    {
        // Xác thực dữ liệu từ form
        $validatedData = $request->validate([
            'full_name' => 'required|max:255',
            'phone_number' => 'required|regex:/^[0-9]{10}$/',
            'email' => 'required|email',
            'address' => 'required',
            'payment_type' => 'required|in:cod,online',
            'variant_id' => 'required|exists:variants,id',
            'variant_quantity' => 'required|integer|min:1',
        ]);

        // Lấy thông tin sản phẩm
        $variant = Variant::find($validatedData['variant_id']);

        // Kiểm tra số lượng tồn kho
        if ($variant->stock < $validatedData['variant_quantity']) {
            return redirect()->route('tt-that-bai')->withErrors([
                'error' => 'Sản phẩm ' . $variant->product->name . ' (Size: ' . $variant->size->name . ') không còn đủ hàng trong kho.'
            ]);
        }


        $totalPrice = $variant->sale_price * $validatedData['variant_quantity'];

        // Tạo mã đơn hàng
        $billCode = 'BILL-' . strtoupper(uniqid());

        // Xử lý thanh toán dựa trên phương thức đã chọn
        if ($validatedData['payment_type'] === 'online') {
            return $this->paymentVNPAY($billCode, $totalPrice, $validatedData, $variant);
        } else {
            // Thanh toán COD, lưu vào cơ sở dữ liệu
            $bill = Bill::create([
                'user_id' => Auth::id(),
                'code' => $billCode,
                'bill_status' => 'pending',
                'payment_type' => $validatedData['payment_type'],
                'payment_status' => 'pending',
                'total_price' => $totalPrice,
                'full_name' => $validatedData['full_name'],
                'phone_number' => $validatedData['phone_number'],
                'address' => $validatedData['address'],
            ]);

            // Lưu thông tin vào bảng bill_items
            BillItem::create([
                'variant_id' => $variant->id,
                'bill_id' => $bill->id,
                'sale_price' => $variant->sale_price,
                'listed_price' => $variant->listed_price,
                'import_price' => $variant->import_price,
                'variant_quantity' => $validatedData['variant_quantity'],
                'product_name' => $variant->product->name,
                'product_image_url' => $variant->product->primary_image_url,
            ]);

            // Giảm số lượng hàng tồn kho
            $variant->decrement('stock', $validatedData['variant_quantity']);

            // $userEmail = Auth::user()->email;
            // Mail::to($userEmail)->send(new OrderConfirmationMail($bill));
            return redirect()->route('tt-thanh-cong')->with('success', 'Đặt hàng thành công!');
        }
    }

    private function paymentVNPAY($billCode, $totalPrice, $validatedData, $variant)
    {
        // Kiểm tra lại tồn kho trước khi thực hiện thanh toán
        if ($variant->stock < $validatedData['variant_quantity']) {
            return redirect()->route('tt-that-bai')->withErrors([
                'error' => 'Sản phẩm ' . $variant->product->name . ' (Size: ' . $variant->size->name . ') không còn đủ hàng trong kho.'
            ]);
        }

        $vnp_TxnRef = $billCode;
        $vnp_Url = "https://sandbox.vnpayment.vn/paymentv2/vpcpay.html";
        $vnp_Returnurl = route('checkout.vnpay.return');
        $vnp_TmnCode = "KA1BV3N8";
        $vnp_HashSecret = "12GUKMUAGMQR4QW57D26MKG56RCYN9G8";

        $vnp_OrderInfo = "Thanh toán VNPAY - Mã đơn hàng: " . $vnp_TxnRef;
        $vnp_Amount = $totalPrice * 100;
        $vnp_Locale = "VN";
        $vnp_IpAddr = $_SERVER['REMOTE_ADDR'];

        // Chuẩn bị dữ liệu gửi đến VNPAY
        $inputData = [
            "vnp_Version" => "2.1.0",
            "vnp_TmnCode" => $vnp_TmnCode,
            "vnp_Amount" => $vnp_Amount,
            "vnp_Command" => "pay",
            "vnp_CreateDate" => date('YmdHis'),
            "vnp_CurrCode" => "VND",
            "vnp_IpAddr" => $vnp_IpAddr,
            "vnp_Locale" => $vnp_Locale,
            "vnp_OrderInfo" => $vnp_OrderInfo,
            "vnp_OrderType" => 'billpayment',
            "vnp_ReturnUrl" => $vnp_Returnurl,
            "vnp_TxnRef" => $vnp_TxnRef,
        ];

        // Sắp xếp và tạo chuỗi truy vấn
        ksort($inputData);
        $hashdata = http_build_query($inputData);
        $vnpSecureHash = hash_hmac('sha512', $hashdata, $vnp_HashSecret);
        $query = $hashdata . '&vnp_SecureHash=' . $vnpSecureHash;

        // Lưu thông tin tạm thời vào session
        session(['pending_order' => $validatedData]);

        // Chuyển hướng đến VNPAY
        return redirect($vnp_Url . "?" . $query);
    }

    public function vnpayReturn(Request $request)
    {
        // Xử lý kết quả trả về từ VNPAY
        $vnp_ResponseCode = $request->input('vnp_ResponseCode');
        $vnp_TxnRef = $request->input('vnp_TxnRef');
        $vnp_SecureHash = $request->input('vnp_SecureHash');
        $vnp_HashSecret = "12GUKMUAGMQR4QW57D26MKG56RCYN9G8";

        // Lấy dữ liệu không bao gồm mã bảo mật
        $inputData = $request->except('vnp_SecureHash');
        ksort($inputData);
        $hashdata = http_build_query($inputData);
        $secureHash = hash_hmac('sha512', $hashdata, $vnp_HashSecret);

        if ($vnp_SecureHash === $secureHash) {
            if ($vnp_ResponseCode === '00') {
                // Thanh toán thành công
                $validatedData = session('pending_order');
                $variant = Variant::find($validatedData['variant_id']);

                // Kiểm tra tồn kho trước khi tạo hóa đơn
                if ($variant->stock < $validatedData['variant_quantity']) {
                    return redirect()->route('tt-that-bai')->withErrors([
                        'error' => 'Sản phẩm ' . $variant->product->name . ' (Size: ' . $variant->size->name . ') không còn đủ hàng trong kho.'
                    ]);
                }

                $totalPrice = $variant->sale_price * $validatedData['variant_quantity'];

                // Tạo hóa đơn
                $bill = Bill::create([
                    'user_id' => Auth::id(),
                    'code' => $vnp_TxnRef,
                    'bill_status' => 'pending',
                    'payment_type' => 'online',
                    'payment_status' => 'completed',
                    'total_price' => $totalPrice,
                    'full_name' => $validatedData['full_name'],
                    'phone_number' => $validatedData['phone_number'],
                    'address' => $validatedData['address'],
                ]);

                // Lưu chi tiết hóa đơn
                BillItem::create([
                    'variant_id' => $variant->id,
                    'bill_id' => $bill->id,
                    'sale_price' => $variant->sale_price,
                    'listed_price' => $variant->listed_price,
                    'import_price' => $variant->import_price,
                    'variant_quantity' => $validatedData['variant_quantity'],
                    'product_name' => $variant->product->name,
                    'product_image_url' => $variant->product->primary_image_url,
                ]);

                // Giảm số lượng hàng tồn kho
                $variant->decrement('stock', $validatedData['variant_quantity']);

                // Gửi email xác nhận
                $userEmail = Auth::user()->email;
                Mail::to($userEmail)->send(new OrderConfirmationMail($bill));

                session()->forget('pending_order');
                return redirect()->route('tt-thanh-cong')->with('success', 'Thanh toán thành công!');
            } else {
                return redirect()->route('tt-that-bai')->with('error', 'Thanh toán thất bại!');
            }
        } else {
            return redirect()->route('tt-that-bai')->with('error', 'Thông tin không hợp lệ!');
        }
    }
    
}
