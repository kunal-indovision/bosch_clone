<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Auth;
use App\Models\User;
use App\Models\BOM;
use App\Models\Parts;
use App\Models\BOMParts;
use App\Models\ProductionBOMDispatch;
use App\Models\ProductionBOMDispatchesPickedupParts;
use App\Models\RackInventory;
use App\Models\RacksLnventoryLog;
use DB;
use PDF;
use App\Models\Rack;

class ProductionController extends Controller
{
    public function dispatch_parts()
    {

        $data = [];
        $assets = ['bom_part_ajax'];
        $data['logged_in_user_name'] = Auth::user()->first_name . ' ' . Auth::user()->last_name;
        $data['users'] = User::select('id', 'first_name', 'last_name', 'emp_id')->where('status', 1)->orderBy('id', 'desc')->get()->toArray();
        $data['bom_numbers'] = BOM::select('id', 'bom_number')->where('status', 'Active')->orderBy('id', 'desc')->get()->toArray();
        return view('production.parts-dispatch', $data, compact('assets'));
    }

    public function dispatch_single_part()
    {

        $data = [];

        $assets = ['single_part_dispatch_ajax'];

        $data['logged_in_user_name'] = Auth::user()->first_name . ' ' . Auth::user()->last_name;

        $data['users'] = User::select('id', 'first_name', 'last_name', 'emp_id')->where('status', 1)->orderBy('id', 'desc')->get()->toArray();

        $data['parts'] = Parts::select('id', 'part_number')->where('status', 'Active')->get()->toArray();
        return view('production.single-part-dispatch', $data, compact('assets'));
    }

    public function show_bom_parts(Request $request)
    {

        if ($request->ajax()) {
            DB::enableQueryLog();
            $bom_quanity = $request->bom_quanity;
            $bom_id = $request->input('bom_id');
            if (!is_array($bom_id)) {
                $bom_id = [$bom_id];
            }
            $boms = BOM::select('id')->whereIn('parent_bom_id', $bom_id)->get()->toArray();
            // dd(DB::getQueryLog());


            // $boms_idAr = [];
            // array_push($boms_idAr, $bom_id);
            // if (isset($boms) && count($boms)>0) {
            //     foreach ($boms as $bom) {
            //         array_push($boms_idAr, $bom['id']);
            //     }
            // }

            $boms_idAr = is_array($bom_id) ? $bom_id : [$bom_id];
            if (isset($boms) && count($boms) > 0) {
                foreach ($boms as $bom) {
                    $boms_idAr[] = $bom['id'];
                }
            }
            // dd($boms_idAr);


            // $boms_ids = implode(',', $boms_idAr);
            // dd($boms_ids);


            $bom_parts   = BOMParts::select('bom_parts.fk_part_id', 'parts.part_number', 'bom_parts.description', 'bom_parts.usage_count as per_bom_usage')->join('parts', 'bom_parts.fk_part_id', '=', 'parts.id')->whereIn('bom_parts.fk_bom_id', $boms_idAr)->where('bom_parts.status', 'Active')->get()->toArray();
            // dd(DB::getQueryLog());


            if (isset($bom_parts) && count($bom_parts) > 0) {

                

                $min_stock_partAr = [];

                foreach ($bom_parts as $key => $bom_part) { 
                    // Calculate pickup quantity based on per_bom_usage and bom_quantity
                    $bom_parts[$key]['pickup_qty'] = $pickup_qty = $bom_part['per_bom_usage'] * $bom_quanity;
                
                    // Check availability of parts in RackInventory
                    $check_availability = RackInventory::select(DB::raw('SUM(parts_quantity - IF(out_parts_quantity > 0, out_parts_quantity, 0)) AS remaining'))
                        ->where('fk_part_id', $bom_part['fk_part_id']);
                
                    // Retrieve the earliest pickup location
                    $pickup_location_query = RackInventory::select('location')
                        ->join('racks_cell_partitions', 'racks_cell_partitions.id', '=', 'racks_inventory.fk_racks_cell_partition_id')
                        ->join('grn', 'grn.id', '=', 'racks_inventory.fk_grn_id')
                        ->where('racks_inventory.fk_part_id', $bom_part['fk_part_id'])
                        ->orderBy('grn.date', 'asc')
                        ->first();
          
                    $pickup_location = $pickup_location_query ? $pickup_location_query->location : null;
                    $bom_parts[$key]['pickup_location'] = $pickup_location;
                
                    // Calculate available stock
                    $available_qty = 0;
                    if ($check_availability->exists()) {
                        $available_qty = (int) $check_availability->value('remaining') ?? 0;
                        $bom_parts[$key]['available_stock'] = $available_qty;
                
                        // If available stock is less than pickup quantity, add part to low stock array
                        if ($available_qty < $pickup_qty) {
                            $min_stock_partAr[$bom_part['part_number']] = $available_qty;
                        }
                    }
                }
                
                // dd($bom_parts);

                

                $message = count($bom_parts) . " parts numbers found for seleted BOM.";
                $status  = "success";

                // if (!empty($min_stock_partAr)) {
                //     $minimum_qty = min($min_stock_partAr);
                //     $message = 'Minimum available stock of ' . array_flip($min_stock_partAr)[$minimum_qty] . ' is ' . $minimum_qty;
                //     $status  = "danger";
                //     $bom_parts = NULL;
                // }
                // if (!empty($min_stock_partAr)) {
                //     $minimum_qty = min($min_stock_partAr);
                //     $stockmsg='Minimum available stock of ' . array_flip($min_stock_partAr)[$minimum_qty] . ' is ' . $minimum_qty;
                //     $response['stock'] =$stockmsg;

                // }
                

                $response['status']  = $status;
                $response['message'] = $message;
                $response['parts']   = $bom_parts;
            } else {

                $response['status']  = "danger";
                $response['message'] = "No parts numbers found for selectd BOM.";
                $response['parts']   = $bom_parts;
            }

            return response()->json($response);
        }
    }

    public function get_single_part_available_qty_ajax(Request $request)
    {

        if ($request->ajax()) {

            $part_id = $request->part_id;
            $pickup_qty = $request->pickup_qty;

            if (isset($pickup_qty) && $pickup_qty > 0) {

                $part = [];

                $check_availability = RackInventory::select(DB::raw(' sum(parts_quantity-IF(out_parts_quantity>0,out_parts_quantity,0)) as remaining'))->where('fk_part_id', $part_id);



                $pickup_locations = RackInventory::select('location', 'grn.date')->join('racks_cell_partitions', 'racks_cell_partitions.id', '=', 'racks_inventory.fk_racks_cell_partition_id')->join('grn', 'grn.id', '=', 'racks_inventory.fk_grn_id')->orderBy('grn.date', 'asc')->where('racks_inventory.fk_part_id', $part_id);


                if ($pickup_locations->exists()) {
                    $pickup_location = $pickup_locations->get()->first()->toArray()['location'];
                }

                $part['pickup_location'] = $pickup_location ?? NULL;

                if ($check_availability->exists()) {
                    $check_available_qty = $check_availability->get()->first()->toArray();
                    $part['available_stock'] = $available_qty = (int)$check_available_qty['remaining'] ?? 0;

                    if ($available_qty < $pickup_qty) {
                        return response()->json(['status' => 'danger', 'message' => 'Stock not available for entered pickup quantity. Available stock : ' . $available_qty]);
                    }
                }

                $message = "Selected part available in stock.";
                $status  = "success";

                $response['status']  = $status;
                $response['message'] = $message;
                $response['part']   = $part;
            } else {

                $response['status']  = "danger";
                $response['message'] = "Part pickup quantity should be greater than zero.";
                $response['part']   = $part;
            }

            return response()->json($response);
        }
    }

    public function create_dispatch(Request $request)
    {
        DB::enableQueryLog();


        // dd($request->dispatch_id);
        $bom_ids = $request->bom_id;
        // dd($request->bom_id);
        foreach ($bom_ids as $bom_id) {
        $dispatchAr['id'] = $dispatch_id = $request->dispatch_id;
        $dispatchAr['fk_bom_id']         = $bom_id;
        $dispatchAr['fk_part_id']        = $request->part_id;
        $dispatchAr['quantity']          = $request->quantity;
        $dispatchAr['fk_receiver_id']    = $request->receiver_id;
        $dispatchAr['remarks']           = $request->remarks;
        $dispatchAr['created_by']        = Auth::id();

        $saved = ProductionBOMDispatch::upsert($dispatchAr, ['id'], ['id', 'fk_bom_id', 'fk_part_id', 'quantity', 'remarks', 'created_by', 'updated_by']);

        if (empty($dispatch_id)) {
            $dispatch = ProductionBOMDispatch::select('id')->orderBy('id', 'desc')->get()->first();
            $dispatch_id = $dispatch['id'] ?? NULL;
        }
    }
        // dd(DB::getQueryLog());

        if ($saved) {
            // dd($saved);
            return redirect(route('production.show_dispatch', ['dispatch_id' => $dispatch_id]))->withSuccess('Data saved successfully.');
        } else {
            return redirect(route('production.dispatch'))->withErrors('Data could not be saved, please try again.')->withInput();
        }
    }


    public function show_dispatch($dispatch_request_id)
    {
        // dd($dispatch_request_id);

        $data = [];
        $data = $this->dispatch_data($dispatch_request_id);
        return view('production.show-dispatch', $data);
    }


    public function dispatch_data($dispatch_request_id)
    {

        $data['dispatch_request_id'] = $dispatch_request_id;

        $dispatch = ProductionBOMDispatch::select('production_bom_dispatches.id', 'production_bom_dispatches.fk_bom_id', 'production_bom_dispatches.fk_part_id', 'bom.bom_number', 'production_bom_dispatches.quantity', 'users.first_name', 'users.last_name', 'users.emp_id', 'production_bom_dispatches.remarks')->leftjoin('bom', 'bom.id', '=', 'production_bom_dispatches.fk_bom_id')->leftjoin('users', 'users.id', '=', 'production_bom_dispatches.fk_receiver_id')->where('production_bom_dispatches.id', $dispatch_request_id);

        $dispatch_bom = null;

        if ($dispatch->exists()) {
            $data['dispatch_bom'] = $dispatch_bom = $dispatch->get()->first()->toArray();
        }

        $data['logged_in_user_name'] = Auth::user()->first_name . ' ' . Auth::user()->last_name;

        if (isset($dispatch_bom['fk_bom_id'])) {
            $boms     = BOM::select('id')->where('parent_bom_id', $dispatch_bom['fk_bom_id'])->get()->toArray();
        }

        $boms_idAr = [];
        array_push($boms_idAr, $dispatch_bom['fk_bom_id'] ?? NULL);
        if (isset($boms) && count($boms) > 0) {
            foreach ($boms as $key => $bom) {
                array_push($boms_idAr, $bom['id']);
            }
        }

        $boms_ids = implode(',', $boms_idAr);

        $data['bom_parts'] = $bom_parts = BOMParts::select('parts.id as part_id', 'parts.part_number', 'bom_parts.description', 'bom_parts.usage_count as per_bom_usage')->join('parts', 'bom_parts.fk_part_id', '=', 'parts.id')->whereIn('bom_parts.fk_bom_id', $boms_idAr)->where('bom_parts.status', 'Active')->get()->toArray();

        if (!empty($dispatch_bom['fk_part_id'])) {
            $data['bom_parts'] =  $bom_parts = Parts::select('id as part_id', 'part_number')->where('id', $dispatch_bom['fk_part_id'])->get()->toArray();
        }

        if (isset($bom_parts) && count($bom_parts) > 0) {
            foreach ($bom_parts as $key => $bom_part) {

                # $check = ProductionBOMDispatchesPickedupParts::where(['fk_production_bom_dispatch_id'=>$dispatch_request_id,'fk_part_id'=>$bom_part['part_id']])->pluck('fk_part_id')->first(); 

                $check = ProductionBOMDispatchesPickedupParts::select('grn_number', 'actual_pickedup_quantity')->join('racks_inventory', 'racks_inventory.id', '=', 'production_bom_dispatches_pickedup_parts.fk_rack_inventory_id')->leftjoin('grn', 'grn.id', '=', 'racks_inventory.fk_grn_id')->where(['fk_production_bom_dispatch_id' => $dispatch_request_id, 'production_bom_dispatches_pickedup_parts.fk_part_id' => $bom_part['part_id']])->get()->first();

                if ($check) {
                    $data['bom_parts'][$key]['grn_number'] = $check['grn_number'];
                    $data['bom_parts'][$key]['is_picked']  = 'Yes';
                    $data['bom_parts'][$key]['actual_pickedup_quantity'] = $check['actual_pickedup_quantity'];
                } else {
                    $data['bom_parts'][$key]['grn_number'] = null;
                    $data['bom_parts'][$key]['is_picked']  = 'No';
                    $data['bom_parts'][$key]['actual_pickedup_quantity'] = 0;
                }

                $data['bom_parts'][$key]['pickup_qty'] = isset($bom_part['per_bom_usage']) ? $bom_part['per_bom_usage'] * $dispatch_bom['quantity'] : $dispatch_bom['quantity'];
            }
        }

        return $data;
    }


    public function pickup_parts($dispatch_id, $part_id, $pickup_quantity)
    {

        $data['dispatch_id']     = $dispatch_id;
        $data['part_id']         = $part_id;
        $data['pickup_quantity'] = $pickup_quantity;
        $data['parts_location']  = $d = RackInventory::select(
            'racks_inventory.id as rack_inventory_id',
            'racks.id as rack_id',
            'racks.name as rack_name',
            'racks.location as rack_location',
            'racks_cell_partitions.location',
            'racks_inventory.fk_rack_cell_id',
            'racks_inventory.fk_part_id',
            DB::raw(' sum(racks_inventory.parts_quantity-IF(racks_inventory.out_parts_quantity>0,racks_inventory.out_parts_quantity,0)) as balance')
        )

            ->join('racks_cell_size', 'racks_cell_size.id', '=', 'racks_inventory.fk_rack_cell_id')
            ->join('racks', 'racks.id', '=', 'racks_cell_size.fk_rack_id')
            ->join('racks_cell_partitions', 'racks_cell_partitions.id', '=', 'racks_inventory.fk_racks_cell_partition_id')
            ->join('grn', 'grn.id', '=', 'racks_inventory.fk_grn_id')
            ->orderBy('grn.date', 'asc')
            ->where('racks_inventory.fk_part_id', $part_id)
            ->groupBy(
                'racks_inventory.id',
                'racks_inventory.fk_part_id',
                'racks_inventory.fk_rack_cell_id',
                'racks.id',
                'racks.name',
                'racks.location',
                'racks_cell_partitions.location',
            )
            ->havingRaw('balance > 0')
            ->get()
            ->toArray();

        return view('production.pickup-parts', $data);
    }

    public function dispatch_part_pickedup(Request $request)
    {

        $dispatch_id                = $request->dispatch_id;
        $part_id                    = $request->part_id;
        $rack_inventory_id          = $request->rack_inventory_id;
        $pickup_quantity            = $request->actual_pickedup_quantity;
        $rack_ids                   = $request->rack_ids;
        $locations                  = $request->locations;
        $rack_cell_id               = $request->rack_cell_id;

        if (json_validate($request->scan_pickup_parts_location)) {

            $scan_pickup_parts_location = json_decode($request->scan_pickup_parts_location);


            if (in_array($scan_pickup_parts_location->rack_id, $rack_ids) && in_array($scan_pickup_parts_location->location, $locations)) {

                DB::beginTransaction();

                try {

                    $rack_inventory_id = $rack_inventory_id[0];

                    $previous_out_qty = RackInventory::where('id', $rack_inventory_id)->value('out_parts_quantity');

                    $update_inventoryAr['id']                   = $rack_inventory_id;
                    $update_inventoryAr['description']          = 'Part send out for production dispatch id : ' . $dispatch_id;
                    $update_inventoryAr['out_parts_quantity']   = $previous_out_qty + $pickup_quantity;
                    $update_inventoryAr['updated_by']           = Auth::id();

                    ### Add entry in rack_inventory table for parts out. 

                    $saved = RackInventory::upsert($update_inventoryAr, ['id'], ['id', 'description', 'out_parts_quantity', 'updated_by']);


                    ### Add entry IN production_bom_dispatches_pickedup_parts table for picked up parts. 

                    $pickup_partsAr['id']                               = NULL;
                    $pickup_partsAr['fk_production_bom_dispatch_id']    = $dispatch_id;
                    $pickup_partsAr['fk_part_id']                       = $part_id;
                    $pickup_partsAr['actual_pickedup_quantity']         = $pickup_quantity;
                    $pickup_partsAr['fk_rack_inventory_id']             = $rack_inventory_id;
                    $pickup_partsAr['created_by']                       = Auth::id();

                    $saved = ProductionBOMDispatchesPickedupParts::upsert($pickup_partsAr, ['id'], ['id', 'fk_production_bom_dispatch_id', 'fk_part_id', 'created_by']);

                    $logAr = [
                        'fk_rack_inventory_id' => $rack_inventory_id,
                        'quantity'             => $pickup_quantity,
                        'movement_type'        => 'Out',
                        'remarks'              => 'Parts sent out for production dispatch id : ' . $dispatch_id,
                        'created_by'           => Auth::id(),
                        'created_at'           => now()
                    ];

                    RacksLnventoryLog::insert($logAr);

                    DB::commit();
                    $method_name = 'withSuccess';
                    $message  = 'Data saved successfully.';
                } catch (Exception $e) {
                    DB::rollback();
                    $method_name = 'withErrors';
                    $message = $e->getMessage();
                }

                return redirect(route('production.show_dispatch', ['dispatch_id' => $dispatch_id]))->$method_name($message);
            } else {
                return redirect(route('production.pickup_parts', ['part_id' => $part_id, 'quantity' => $pickup_quantity, 'dispatch_id' => $dispatch_id]))->withErrors('Wrong rack scanned, please try again.')->withInput();
            }
        } else {
            return redirect(route('production.pickup_parts', ['part_id' => $part_id, 'quantity' => $pickup_quantity, 'dispatch_id' => $dispatch_id]))->withErrors('Invalid input, please try again.')->withInput();
        }
    }

    function dispatches_list()
    {

        $data['dispatches'] = ProductionBOMDispatch::select('production_bom_dispatches.id', 'bom.bom_number', 'quantity', 'users.first_name', 'users.emp_id', 'production_bom_dispatches.created_at', 'production_bom_dispatches.status')->leftjoin('bom', 'bom.id', '=', 'production_bom_dispatches.fk_bom_id')->join('users', 'users.id', '=', 'production_bom_dispatches.fk_receiver_id')->orderBy('production_bom_dispatches.id', 'desc')->get()->toArray();

        return view('production.dispatches-list', $data);
    }

    function print_dispatch($dispatch_id)
    {

        $data = [];

        ProductionBOMDispatch::where(['id' => $dispatch_id, 'status' => 'Pending'])->update(['status' => 'Dispatched', 'updated_by' => Auth::id()]);

        $data = $this->dispatch_data($dispatch_id);

        $pdf  = PDF::loadView('production.print-dispatch', $data);

        return $pdf->stream($dispatch_id . '-dispatch.pdf');
    }

    function return_parts()
    {
        $data = [];
        $data['logged_in_user_name'] = Auth::user()->first_name . ' ' . Auth::user()->last_name;
        $data['users'] = User::select('id', 'first_name', 'last_name', 'emp_id')->where('status', 1)->orderBy('id', 'desc')->get()->toArray();
        $data['parts'] = Parts::select('id', 'part_number')->where('status', 'Active')->orderBy('id', 'desc')->get()->toArray();
        $data['racks'] = Rack::select('id', 'name', 'location')->where('status', 'Active')->orderBy('id', 'desc')->get()->toArray();

        $assets = ['rack_cell_ajax'];

        return view('production.parts-return', $data, compact('assets'));
    }

    function save_return_parts(Request $request)
    {

        $returnAr['returned_by']       =   $request->return_by_id;
        $returnAr['fk_part_id']        =   $request->part_id;
        $returnAr['parts_quantity']    =   $request->quantity;
        $returnAr['fk_rack_cell_id']   =   $request->rack_cell_id;
        $returnAr['description']       =   $request->remarks;
        $returnAr['parts_movement']    =   'In';
        $returnAr['created_by']        =   Auth::id();

        $saved = RackInventory::upsert($returnAr, ['id'], ['id', 'fk_rack_cell_id', 'description', 'fk_part_id', 'parts_quantity', 'parts_movement', 'created_by', 'returned_by']);

        if ($saved) {
            return redirect(route('production.return_parts_list'))->withSuccess('Data saved successfully.');
        } else {
            return redirect(route('production.return'))->withErrors('Data could not be saved, please try again.')->withInput();
        }
    }

    function return_parts_list(Request $request)
    {
        $data['parts'] = RackInventory::select('parts.part_number', 'parts_quantity', 'racks_inventory.description', 'users.first_name', 'users.emp_id', 'racks_inventory.created_at')->join('parts', 'parts.id', '=', 'racks_inventory.fk_part_id')->join('users', 'users.id', '=', 'racks_inventory.returned_by')->whereNotNull('returned_by')->orderBy('racks_inventory.id', 'desc')->get()->toArray();
        return view('production.return-list', $data);
    }
}
