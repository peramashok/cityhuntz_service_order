<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Invoice</title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Open Sans', sans-serif;
            background-color: #f5f5f5;
            color: #333;
            font-size: 14px;
            line-height: 1.5;
        }

        .container {
            max-width: 400px;
            margin: 20px auto;
            padding: 15px;
        }

        .invoice-wrapper {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 20px;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .text-left {
            text-align: left;
        }

        .text-muted {
            color: #6c757d;
        }

        .d-flex {
            display: flex;
        }

        .justify-content-between {
            justify-content: space-between;
        }

        .justify-content-center {
            justify-content: center;
        }

        .align-items-center {
            align-items: center;
        }

        .mb-1 { margin-bottom: 0.25rem; }
        .mb-2 { margin-bottom: 0.5rem; }
        .mb-3 { margin-bottom: 1rem; }
        .mt-1 { margin-top: 0.25rem; }
        .mt-2 { margin-top: 0.5rem; }
        .my-2 { margin-top: 0.5rem; margin-bottom: 0.5rem; }
        .pt-1 { padding-top: 0.25rem; }
        .pt-3 { padding-top: 1rem; }
        .px-3 { padding-left: 1rem; padding-right: 1rem; }
        .p-3 { padding: 1rem; }

        .logo {
            width: 80px;
            height: 80px;
            object-fit: contain;
        }

        .restaurant-name {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .restaurant-address {
            font-size: 12px;
            color: #555;
            word-break: break-word;
        }

        h5 {
            font-size: 13px;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .order-info-box {
            border: 1px dashed #979797;
            padding: 12px;
            border-radius: 8px;
            margin-top: 10px;
            margin-bottom: 10px;
        }

        .border-bottom-dashed {
            border-bottom: 1px dashed #979797;
        }

        /* Table Styles */
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        .table th, .table td {
            padding: 8px 5px;
            vertical-align: top;
        }

        .table th {
            border-bottom: 1px dashed #979797 !important;
            font-weight: 600;
            font-size: 12px;
            text-align: left;
        }

        .table td {
            font-size: 13px;
        }

        .table .text-right {
            text-align: right;
        }

        .w-28p {
            width: 28%;
        }

        .text-break {
            word-break: break-word;
        }

        .font-size-sm {
            font-size: 11px;
        }

        .font-weight-bold {
            font-weight: 600;
        }

        /* Summary Section */
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            font-size: 13px;
        }

        .summary-row.total {
            font-size: 16px;
            font-weight: 600;
        }

        .fw-500 {
            font-weight: 500;
        }

        .fz-20px {
            font-size: 18px;
        }

        /* Print Button */
        .print-btn {
            background: linear-gradient(135deg, #FC6A57 0%, #f9896b 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }

        .print-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(252, 106, 87, 0.4);
        }

        .btn-back {
            background: #dc3545;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            margin-left: 10px;
            text-decoration: none;
            display: inline-block;
        }

        .btn-back:hover {
            background: #c82333;
        }

        .buttons-container {
            text-align: center;
            margin-bottom: 15px;
        }

        .divider {
            border-bottom: 1px dashed #979797;
            margin: 10px 0;
        }

        .gap-2 {
            gap: 0.5rem;
        }

        .d-block {
            display: block;
        }

        .copyright {
            font-size: 11px;
            color: #666;
        }

        /* Print Styles */
        @media print {
            body {
                background: white;
            }
            .container {
                margin: 0;
                padding: 0;
                max-width: 100%;
            }
            .invoice-wrapper {
                box-shadow: none;
                padding: 10px;
            }
            .non-printable {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Print Button -->
        <div class="buttons-container non-printable">
            <button class="print-btn" onclick="window.print()">Print Invoice</button>
        </div>

        <div class="invoice-wrapper">
            <!-- Logo -->
            <div class="text-center pt-3">
                <img src="data:image/png;base64,{{ $logoBase64 }}" style="width: 185px;height: 50px" class="logo" alt="Restaurant Logo">
            </div>

            <!-- Restaurant Info -->
            <div class="text-center pt-3 mb-3">
                <h5 class="restaurant-name">{{$order->restaurant->name}}</h5>
                <h5 class="restaurant-address">
                    {{$order->restaurant->address}},{{$order->restaurant->state_info->state_name}},{{$order->restaurant->city}}-{{$order->restaurant->zipcode}}
                </h5>
                <h5 class="text-muted">{{14/Apr/2025 06:19}} {{date('d/m/Y h:i A", strtotime($order->created_at))}}</h5>
                <h5>
                    <span>Phone</span> <span>:</span> <span>{$order->restaurant->phone}}</span>
                </h5>
            </div>

            <!-- Order Type -->
            <h5 class="d-flex justify-content-between gap-2">
                <span>Order Type</span>
                <span>{{$order->order_type=='delivery' ? 'Home Delivery' : str_replace("_", " ", $order->order_type);}}</span>
            </h5>
@php $data = json_decode($order->delivery_address, true); @endphp
            <!-- Order Info Box -->
            <div class="order-info-box">
                <h5 class="d-flex justify-content-between gap-2">
                    <span class="text-muted">Order ID</span>
                    <span>{{sprintf("%05d", $order->id)}}</span>
                </h5>
                <h5 class="d-flex justify-content-between gap-2">
                    <span class="text-muted">Customer Name</span>
                    <span>{{$data['contact_person_name']}}</span>
                </h5>
                <h5 class="d-flex justify-content-between gap-2">
                    <span class="text-muted">Phone</span>
                    <span>{{$data['contact_person_number']}}</span>
                </h5>
                <h5 class="d-flex justify-content-between gap-2 text-break">
                    <span class="text-muted" style="white-space: nowrap;">Delivery Address</span>
                    <span class="text-right">
                        {{$data['address']}}
                    </span>
                </h5>
            </div>

            <!-- Items Table -->
            <table class="table mt-1 mb-1">
                <thead>
                    <tr>
                        <th>QTY</th>
                        <th>Item</th>
                        <th class="text-right">Price</th>
                    </tr>
                </thead>
                <tbody>
                  @php 
                   $totalFoodCost=0;$delivery_charge=0;$dm_tips=0;$discount_amount=0;$processing_charges=0;$tax_amount=0;

            $finalAmount=0;

            @endphp
                  @foreach($orderDetailsResults as $singleOrder)
                     @php $foodCost=$singleOrder->price*$singleOrder->quantity;  @endphp
                    <tr>
                        <td>{{$singleOrder->quantity}}x</td>
                        <td class="text-break">
                            {{$singleOrder->quantity}}<br>
                            <div class="font-size-sm text-muted">
                                <span>Price : </span>
                                <span class="font-weight-bold">$ {{number_format($singleOrder->quantity, 2)}}</span>
                            </div>
                        </td>
                        <td class="text-right w-28p">$ {{number_format($foodCost, 2)}}</td>
                    </tr>

                    @php 
                       $food_cost=$food_cost+$foodCost; 
                       $totalFoodCost=$totalFoodCost+$foodCost;
                    @endphp
                    @endforeach
                     $discountAmount = $singleOrder->discount_amount ?? 0;
                      $processing_charges=$processing_charges+$singleOrder->processing_charges;
                    @php  
                @endphp
                </tbody>
            </table>

            <div class="divider"></div>

            <!-- Price Summary -->
            <div class="px-3">
                <div class="summary-row">
                    <span class="text-muted">Items Price</span>
                    <span>$ {{number_format($food_cost, 2)}}</span>
                </div>
                <div class="summary-row">
                    <span class="text-muted">Addon Cost</span>
                    <span>$ 0.00</span>
                </div>

                <div class="divider"></div>

                <div class="summary-row fw-500">
                    <span>Subtotal</span>
                    <span>$ {{number_format($singleOrder->food_cost, 2)}}</span>
                </div>

                <div class="divider"></div>

                <div class="summary-row">
                    <span class="text-muted">Discount</span>
                    <span>- $ {{$discountAmount}}</span>
                </div>
                <div class="summary-row">
                    <span class="text-muted">Coupon discount</span>
                    <span>- $ {{number_format($singleOrder->discount_amount, 2)}}</span>
                </div>
               <!--  <div class="summary-row">
                    <span class="text-muted">Vat/tax</span>
                    <span>$ 9.00</span>
                </div> -->
                <div class="summary-row">
                    <span class="text-muted">Delivery man tips</span>
                    <span>$ {{0.00}}</span>
                </div>
                <div class="summary-row">
                    <span class="text-muted">Delivery charge</span>
                    <span>$ {{number_format($singleOrder->delivery_charge, 2)}}</span>
                </div>

                <div class="summary-row">
                    <span class="text-muted">Platform Fee</span>
                    <span>$ {{number_format($singleOrder->processing_charges, 2)}}</span>
                </div>

                <div class="divider"></div>

                <div class="summary-row total">
                    <span>Total</span>
                    <span>$ 219.00</span>
                </div>
            </div>

            <div class="divider"></div>

            <!-- Payment Method -->
            <div class="d-flex justify-content-between">
                <span>Paid by : Cash on delivery</span>
            </div>

            <div class="divider"></div>

            <!-- Thank You -->
            <h5 class="text-center pt-1 mb-1">
                <span class="d-block fw-500" style="font-size: 16px;">THANK YOU</span>
            </h5>
            <div class="text-center" style="font-size: 12px;">For ordering food from Cityhuntz</div>

            <div class="divider"></div>

            <!-- Copyright -->
            <span class="d-block text-center copyright">© {{date('Y')=='2026' ? '2026' : date('Y')}}2026 Cityhuntz. All right reserved</span>
        </div>
    </div>
</body>
</html>