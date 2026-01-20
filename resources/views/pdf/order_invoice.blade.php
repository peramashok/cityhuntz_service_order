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
            <div class="invoice-no" style="margin-top:10px;">Invoice Number #{{$order->order_no}}</div>
        </div>
    </div>
 <div style="clear: both;">&nbsp;</div>
     <hr class="divider" >
      <div style="clear: both;">&nbsp;</div>
     <div style="display: flex; width: 800px; font-size: 14px; line-height: 20px; margin-bottom: 10px;">
        <!-- LEFT: ORDER DETAILS -->
        <div style="width: 300px;float: left;">
            <p style="margin: 0 0 4px 0;"><strong>Invoice ID:</strong> {{$order->order_no}}</p>
            <p style="margin: 0 0 4px 0;"><strong>Payment Status:</strong> {{$order->payment_status}}</p>
            <p style="margin: 0;"><strong>Invoice Date:</strong> {{date("Y-m-d h:i A", strtotime($order->created_at))}}</p>
        </div>

        <!-- RIGHT: BILLING ADDRESS with ZERO GAP -->
        <div style="width: 500px; text-align: left; margin-left: 0;float: left;">
            <p style="margin: 0 0 4px 0;"><strong>Billing Address</strong></p>

            @php
            $contact=json_decode($order->delivery_address, true);
            @endphp
            <p style="margin: 0;">{{ $contact['contact_person_name']  }},</p>
            <p style="margin: 0;">{{ $contact['address']  }},</p>
            <p style="margin: 0;">{{ $contact['contact_person_number']  }},</p>
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
            $totalFoodCost=0; $food_cost=0;
            $i=1;
        @endphp
        @foreach($orderDetailsResults as $singleOrder)
         @php 
            $foodCost=$singleOrder->price*$singleOrder->quantity;  
            $food=json_decode($singleOrder->food_details, true);

            $addOns=$singleOrder->add_ons;



                   
  

         @endphp
        <tr>
             <td>{{$i++}}</td> <td>{{$food['name']}}</td><td>{{$singleOrder->quantity}}</td> <td>{{number_format($food['price']*$singleOrder->quantity, 2)}}</td> 
        </tr>

        @php

        $variationPrice = 0;
                    
                if (!empty($singleOrder['variation'])) {

                    $variationData=$singleOrder['variation'];

                    if (is_string($variationData)) {
                        $variationData = json_decode($variationData, true);
                    }

                    foreach ($variationData as $singleVariation) {
                    
                        $variationPrice += $singleVariation['optionPrice'] ?? 0;
                        @endphp
                            <tr><td>{{$i++}}</td><td>{{$singleVariation['label'] ?? 'Variation Option Name'}}</td><td>{{$singleVariation['quantity']}}</td><td>{{number_format($singleVariation['quantity']*$singleVariation['optionPrice'], 2)}}</td></tr>
                        @php
                    }
                }

                
                $addonsPrice = 0;
                 

                if (!empty($singleOrder['add_ons'])) {

                    $addsOns=$singleOrder['add_ons'];

                    if (is_string($addsOns)) {
                        $addsOns = json_decode($addsOns, true);
                    }

                   foreach ($addsOns as $addon) {
                        $addCost=($addon['price']*$addon['quantity']);
                        $addonsPrice +=$addCost  ?? 0;
                         @endphp

                          <tr><td>{{$i++}}</td><td>{{$addon['name']}}</td><td>{{$addon['quantity']}}</td><td>{{number_format($addCost, 2)}}</td></tr>

                        @php

                    }
                }
             @endphp
            @php 
               $food_cost=$food_cost+$foodCost+$variationPrice+$addonsPrice; 
               
              
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
            <td colspan="3" style="text-align:right;">Discount</td><td>-$ {{number_format($order->coupon_discount_amount-$order->restaurant_discount_amount,2)}}</td>
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
