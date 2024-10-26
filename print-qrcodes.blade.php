<!DOCTYPE html>
<html>
<head>
    <title>QR Codes</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        .qr-container {
            margin-bottom: 20px;
            text-align: center;
        }

        ul li {
            float: left;
            margin: 10px;
            list-style-type: none;
        }

        .qr-grid {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-around;
        }
        .qr-item {
        /*  flex: 1 1 calc(30% - 20px);*/
        width: 100%;
            box-sizing: border-box;
        }
        @media (max-width: 768px) {
            .qr-item {
                flex: 1 1 calc(50% - 20px);
            }
        }
        @media (max-width: 480px) {
            .qr-item {
                flex: 1 1 100%;
            }
        }
        .centered-button {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 20px;
        }
        .styled-button {
            background-color: #4CAF50;
            border: none;
            color: white;
            padding: 15px 32px;
            text-align: center;
            text-decoration: none;
            display: inline-block;
            font-size: 16px;
            margin: 4px 2px;
            cursor: pointer;
            border-radius: 8px;
        }

        td {
            text-align: left;
            font-size: 11px;
        }
    </style>
</head>
<body>

    <center><h1>Please print & Paste below QR Codes on bins to place bins in Racks : -</h1></center>
    <div id="qrCodesContainer" class="qr-grid">
        <?php //pr($qrData['qr_codes']); die(); ?>
        @foreach ($qrData['qr_codes'] as $key=> $item)
        @php
        $totalPartsQty = $qrData['qr_code_data'][$key]['total_parts_qty'];
        $binsQty = $qrData['qr_code_data'][$key]['bins_qty'];
        $partsPerBin = intdiv($totalPartsQty, $binsQty);
        $remainder = $totalPartsQty % $binsQty;
        // dd($key);

    @endphp
            <div class="qr-item qr-container">
                <ul>
                    <li>
                {!! $item['qrCode'] !!}
                </li>
                <li>
                    <table>
                        <tr>
                            <td>Bin No.</td>
                            <td>:</td>
                            <td>{{ $item['bin_serial_number'] }}/{{(($bins_qty)<10)?'0'.$bins_qty:$bins_qty}}</td>
                        </tr>

                        <tr>
                            <td>GRN</td>
                            <td>:</td>
                            <td>{{ $grn_number }}</td>
                        </tr>

                        <tr>
                            <td>GRN Date</td>
                            <td>:</td>
                            <td>{{ date('d-m-Y',strtotime($qrData['qr_code_data'][$key]['grn_date'])) }}</td>
                        </tr>

                        <tr>
                            <td>P/N</td>
                            <td>:</td>
                            <td>{{$qrData['qr_code_data'][$key]['part_number']}}</td>
                        </tr>

                        <tr>
                            <td>P/D</td>
                            <td>:</td>
                            <td>{{substr($qrData['qr_code_data'][$key]['part_description'],0,15)}}</td>
                        </tr>

                        <tr>
                            <td>Bin Qty</td>
                            <td>:</td>
                            <td>{{ $partsPerBin + ($key < $remainder ? 1 : 0) }}</td>
                            
                        </tr>

                        <tr>
                            <td>GRN Qty</td>
                            <td>:</td>
                            <td>{{$qrData['qr_code_data'][$key]['total_parts_qty']}}</td>
                        </tr>
                    </table>
                </li>
                </ul>
            </div>
        @endforeach
        <!-- </div> -->
    </div>

    <div class="centered-button">
        <button class="styled-button" onclick="printQRCode()">Print QR Codes</button>
    </div>

    <script>
        function printQRCode() {
            var printWindow = window.open('', '', 'height=600,width=800');
            var printContent = document.getElementById('qrCodesContainer').innerHTML;
            printWindow.document.write('<html><head><title> </title></head>');
            printWindow.document.write('<style>');
            printWindow.document.write(`

                ul li {
                    float: left !important;
                    list-style-type: none;
                    margin-top:10%;
                }

                ul li:nth-child(odd){
                    margin-left: 16px;
                    margin-right: 2px;
                }

                td {
                    text-align: left;
                    font-size: 12px;
                }
        
                .qr-item {
                    text-align: left;
                    display: block;
                    width: 100%;
                } 

                @media print {
                    body {
                        display: block;
                        width:100%
                    }
                }
            `);
            printWindow.document.write('</style></head><body>');
            printWindow.document.write(printContent);
            printWindow.document.write('</body></html>');
            printWindow.document.close();
            printWindow.focus();
            printWindow.print();
        }
    </script>
</body>
</html>
