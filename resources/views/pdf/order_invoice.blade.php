<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Tax Invoice</title>

<style>
body { font-family: Arial, sans-serif; padding: 20px; background: #fff; }
.invoice-container {
    width: 100%;
    margin: auto;
   /* border: 1px solid #ccc;
    padding: 20px;*/
}

/* Header flex for Sold By & QR code */
.top-section {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.seller-info {
    font-size: 14px;
    width: 500px;
}

.qr-box {
    text-align: center;
}
.qr-box img {
    width: 110px;
    height: 110px;
}
.invoice-no {
    font-size: 12px;
    background: #eee;
    padding: 4px 6px;
    border-radius: 4px;
    margin-top: 5px;
    display: inline-block;
}

.divider {
    border-top: 1px solid #000;
    margin: 15px 0;
}

/* Order + Billing in same line */
.order-billing {
    display: flex;
    justify-content: space-between;
    width: 100%;
    font-size: 14px;
    line-height: 20px;
}

/* Table */
table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
    margin-top: 15px;
}
table th, table td {
    border: 1px solid #000;
    padding: 6px;
    text-align: center;
}
table th {
    background: #f5f5f5;
}
.grand-total-row td {
    border: none !important;
    font-size: 16px;
    font-weight: bold;
}

.sign {
    text-align: right;
    margin-top: 30px;
}

footer {
    font-size: 12px;
/*    border-top: 1px solid #ccc;*/
    margin-top: 25px;
    padding-top: 10px;
}
.footer-logo {
    text-align: right;
    font-size: 14px;
    font-weight: bold;
    color: #00a64f;
}
</style>
</head>

<body>

<div class="invoice-container">

    <h2 style="text-align:center;">Tax Invoice</h2>

    <!-- Sold By & QR in same row -->
    <div class="top-section" style="width: 800px;">
        <div class="seller-info" style="width: 350px;float: left;">
            <strong>Sold By: CityHuntz, Inc.</strong><br><br>
            <strong>Ship-from Address:</strong> 2615 John F Kennedy boulevard, suit 101, Jersey city, New jersey,07306.
        </div>
        <div class="qr-box" style="width: 400px;float: left;">
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=110x110&data=INV-123" style="margin:0; padding:0; display:block;"><br>
            <div class="invoice-no" style="margin-top:10px;">Invoice Number #{{$invoceData->invoice_no}}</div>
        </div>
    </div>
 <div style="clear: both;">&nbsp;</div>
     <hr class="divider" >
      <div style="clear: both;">&nbsp;</div>
     <div style="display: flex; width: 800px; font-size: 14px; line-height: 20px; margin-bottom: 10px;">
        <!-- LEFT: ORDER DETAILS -->
        <div style="width: 300px;float: left;">
            <p style="margin: 0 0 4px 0;"><strong>Invoice ID:</strong> {{$invoceData->invoice_no}}</p>
            <p style="margin: 0 0 4px 0;"><strong>Payment Status:</strong> {{$invoceData->payment_status}}</p>
            <p style="margin: 0;"><strong>Invoice Date:</strong> {{date("Y-m-d h:i A", strtotime($invoceData->created_at))}}</p>
        </div>

        <!-- RIGHT: BILLING ADDRESS with ZERO GAP -->
        <div style="width: 500px; text-align: left; margin-left: 0;float: left;">
            <p style="margin: 0 0 4px 0;"><strong>Billing Address</strong></p>
            <p style="margin: 0;">{{$user->address1}},</p>
            @if($user->address2!='')
            <p style="margin: 0;">{{$user->address2}},</p>
            @endif
            <p style="margin: 0;">{{$user->city}}, {{$user->stateInfo->state_name}}, {{$invoceData->restaurant_id=='' ? $user->countryInfo?->name : 'US'}}-{{$user->zipcode}}</p>
            <p style="margin: 0;">Phone: {{$user->phone}}</p>
        </div>
    </div>
    <div style="clear: both;">&nbsp;</div>
    <table  >
        <tr>
            <th>S.No</th>
            <th>Description</th>
            <th>Quantity</th>
            <th>Gross Amount $</th>
        </tr>
        @php 
            $totalFoodCost=0; 
            $i=1;
        @endphp
        @foreach($orderDetailsResults as $singleOrder)
         @php 
            $foodCost=$singleOrder->price*$singleOrder->quantity;  

         @endphp
        <tr>
            <td>{{$i}}</td><td>{{$singleOrder->food_details['name']}}</td><td>{{$singleOrder->quantity}}</td> <td>{{number_format($singleOrder->food_details['price']*$singleOrder->quantity, 2)}}</td> 
        </tr>
            @php 
               $food_cost=$food_cost+$foodCost; 
               
               $i=$i+1;
            @endphp
            @endforeach
               
           
        <tr class="grand-total-row">
            <td colspan="3" style="text-align:right;">Sub Total</td><td>$ {{number_format($food_cost, 2)}}</td>
        </tr>
         <tr class="grand-total-row">
            <td colspan="3" style="text-align:right;">Delivery Charges</td><td>$ {{number_format($order->delivery_charge,2)}}</td>
        </tr>
         <tr class="grand-total-row">
            <td colspan="3" style="text-align:right;">Platform Fee</td><td>$ {{number_format($order->processing_charges,2)}}</td>
        </tr>
         <tr class="grand-total-row">
            <td colspan="3" style="text-align:right;">Tax</td><td>$ {{number_format($order->total_tax_amount,2)}}</td>
        </tr>
         <tr class="grand-total-row">
            <td colspan="3" style="text-align:right;">Discount</td><td>-$ {{$order->coupon_discount_amount-$order->restaurant_discount_amount}}</td>
        </tr>
        <tr class="grand-total-row">
            <td colspan="3" style="text-align:right;">Grand Total</td><td>$ {{number_format($order->order_amount,2)}}</td>
        </tr>
    </table>
    <p class="sign">Authorised Signatory</p>
    <div style="clear: both;">&nbsp;</div>
     <hr class="divider" style=" border-top: 0.2px solid #ccc;" >
      <div style="clear: both;">&nbsp;</div>
     <div style="display: flex; width: 800px; font-size: 14px; line-height: 20px; margin-bottom: 10px;">
        <div style="width:400px;float: left;">
            Regd Office: 2615 John F Kennedy boulevard, suit 101, Jersey city, New jersey,07306<br>
            Contact CityHuntz: +1 2013757557 | Connect@cityhuntz.com | www.cityhuntz.com/helpcentre
        </div>
        <div class="footer-logo" style="width:250px;float: left;"> 
             <img id="buttonIcon" alt="icon" src="data:image/png;base64,{{ $logoBase64 }}" style="width: 155px;height: 40px">
            <div style="text-align:right; font-family:Arial, sans-serif;">
            <div style="font-size:22px; font-weight:bold; color:#333;">Thank you!</div>
            <div style="font-size:14px; color:#666; margin-top:2px;">Cityhuntz</div>
        </div>
    </footer>
</div>

</body>
</html>
