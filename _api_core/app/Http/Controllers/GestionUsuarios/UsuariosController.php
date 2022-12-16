<?php

namespace App\Http\Controllers\GestionUsuarios;

use Illuminate\Http\Request;
use App\Http\Controllers\MasterController;

// use Symfony\Component\HttpFoundation\File\UploadedFile;
// use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Auth;

use Hash;

class UsuariosController extends MasterController {

	/**
	 * GET Obtiene una lista de USUARIOS con sus roles
	 */
	public function getUsuarios(Request $req) {
		$usuariosList = collect(\DB::select("SELECT u.id as id_usuario, u.username, u.email, u.nombres, u.apellidos, u.carnet, u.nit, u.razon_social
                                    , u.estado_usuario, u.created_at
                                    , u.id_rol, r.rol 
                                    FROM users u 
                                    LEFT JOIN roles r ON u.id_rol = r.id ORDER BY u.nombres "));
		return response()->json([
			'data'   => $usuariosList,
			'status' => 'ok'
		]);
	}

	/**
	 * POST obtener UN USUARIO con sus Roles y nims
	 */
	public function getUser(Request $req) {
		$user = collect(\DB::select("SELECT u.id as id_usuario, u.username, u.email, u.nombres, u.apellidos, u.carnet, u.nit, u.razon_social
                                , u.estado_usuario, u.created_at 
                                , u.id_rol, r.rol 
                                FROM users u 
                                LEFT JOIN roles r ON u.id_rol = r.id
                                WHERE u.id = {$req->id_usuario}"))->first();
		$user->nims = collect(\DB::select("SELECT u.*, r.nombre as municipio, r.codigo_numerico as codigo_municipio 
                                        FROM users_nims u, regiones r 
                                        WHERE u.id_usuario = {$user->id_usuario} AND u.estado_nim != 'Eliminado'
                                        AND u.id_municipio = r.id 
                                        ORDER BY fecha_registro DESC"));

		return response()->json([
			'data'    => $user,
			'status' => 'ok'
		]);
	}

	/**
	 * POST Obtiene un USUARIO con sus NIMs y sus datos (municipio, cod_municipios, etc)
	 */
	public function userNimsActivos(Request $req) {

		/** TODO Realizar las operaciones para obtener el Id apartir de un token recibido */
		$id_usuario = $req->_token;
		// $id_usuario = $req->id_usuario;
		$user = \DB::select("SELECT u.id as id_usuario, u.email, u.nombres, u.apellidos, u.razon_social, u.nit, u.estado_usuario, u.formularios_disponibles, 
                            n.id as id_nim, n.nim, n.estado_nim, n.tipo_formulario,
                            p.nombre as tipo_formulario_nombre, 
                            r.id as id_municipio, r.nombre as municipio, r.codigo_numerico as codigo_municipio
                                FROM users u 
                                LEFT JOIN users_nims  n on u.id = n.id_usuario
                                LEFT JOIN regiones r ON n.id_municipio = r.id 
                                left JOIN parametros p on n.tipo_formulario = p.codigo
                                WHERE u.estado_usuario = 'Activo' and n.estado_nim = 'Activo'
                                AND p.activo
                                AND u.id = {$id_usuario}
                                order by n.tipo_formulario, n.mineral");

		return  response()->json([
			'data'      => $user,
			'status'   => 'ok'
		]);
	}

	/**
	 * POST PAra insertar o actualizar a un usuario
	 */
	public function saveUser(Request $req) {
		$userObj                  = (object)[];
		$userObj->id              = $req->id_usuario ?? null;
		$userObj->username        = strtolower($req->username);
		$userObj->email           = strtolower($req->email);
		$userObj->id_rol          = $req->id_rol;
		$userObj->nombres         = $req->nombres;
		$userObj->apellidos       = $req->apellidos;
		$userObj->estado_usuario  = $req->estado_usuario;
		$userObj->razon_social    = $req->id_rol == 3 ? $req->razon_social : '';
		$userObj->nit             = $req->id_rol == 3 ? $req->nit : '';

		/* Verifica si existe otra coincidencia de email o username con los demas users, para ello se toman solo losIDs diferentes del actual,
        Si no tiene id, se toma un numero negativo para comparar con los demas usuarios, porque con valor null no selecciona nada  */
		$id_user_verificacion = isset($userObj->id) ? $userObj->id : -9999;
		$existeUser = collect(\DB::select("SELECT * FROM users 
                                            WHERE id != {$id_user_verificacion} 
                                            AND ( lower(email) = '{$userObj->email}' or lower(username) = '{$userObj->username}' ) "))->first();
		if ($existeUser)
			return response()->json([
				'data' => $userObj,
				'msg' => ($existeUser->email == $userObj->email) ? "Ya existe un usuario con el email: {$userObj->email}" : "Ya existe el nombre de Usuario: {$userObj->username}",
				"status" => "error"
			]);

		/* Solo si se ha modificado el password */
		if (isset($req->password))
			$userObj->password = bcrypt($req->password);

		$userObj->id_usuario = $this->guardarObjetoTabla($userObj, 'users', true);

		if (isset($req->nims) && count($req->nims) > 0) {
			foreach ($req->nims as $item) {
				$item = (object)$item;
				$objDatosNim                   = (object)[];
				$objDatosNim->id               = (isset($item->id) && !empty($item->id)) ? $item->id : null;
				$objDatosNim->id_usuario       = $userObj->id_usuario;
				$objDatosNim->nim              = $item->nim;
				$objDatosNim->id_municipio     = $item->id_municipio;
				$objDatosNim->tipo_formulario  = $item->tipo_formulario;
				$objDatosNim->mineral          = $item->mineral;
				$objDatosNim->estado_nim       = $item->estado_nim;
				(!$objDatosNim->id) ? $objDatosNim->fecha_registro = $this->now() : false;
				$this->guardarObjetoTabla($objDatosNim, 'users_nims');
			}
		}

		$user = collect(\DB::select("SELECT u.id as id_usuario, u.username, u.email, u.nombres, u.apellidos, u.carnet, u.nit, u.razon_social
                                , u.estado_usuario, u.created_at /*u.departamento, u.municipio, u.codigo_municipio, u.id_municipio, */
                                , u.id_rol, r.rol 
                                FROM users u 
                                LEFT JOIN roles r ON u.id_rol = r.id
                                WHERE u.id = {$userObj->id_usuario}"))->first();

		return response()->json([
			'data'   => $user,
			'msg'    => "Se guardó correctamente",
			"status" => "ok"
		]);
	}


	/**
	 * POST Cambio de Contraseña
	 */
	public function cambioPassword(Request $request) {

		if (!Hash::check($request->password, Auth::user()->password)) {
			// return back()->with("error", "Old Password Doesn't match!");
			return response()->json([
				'status' => 'error',
				'msg' => 'La contraseña actual no es correcta.'
			]);
		}

		$userActual = collect(\DB::select("SELECT * FROM users WHERE id = ?", [Auth::user()->id]))->first();

		$user = (object)[];
		$user->id = Auth::user()->id;
		$user->password = bcrypt($request->new_password);

		$this->guardarObjetoTabla($user, 'users');

		return response()->json([
			'status' => 'ok',
			'msg' => 'Se realizo el cambio de password.'
		]);
	}


	// public function prueba() {
	//     $cadena = "4,1,marcelo";
	//     $token = \Crypt::encrypt($cadena);

	//     $time_start_decrypt = microtime(true);
	// 	$decript_token = \Crypt::decrypt($token);
	//     $time_only_decrypt = microtime(true) - $time_start_decrypt;

	//     $verify_token_id = explode(',',$decript_token)[0];
	//     $correcto = ($verify_token_id == 1);
	//     $time_onlyDecript_explode_compare = microtime(true) - $time_start_decrypt;

	//     $existUser = collect(\DB::select("SELECT * from users where id = {$verify_token_id} "))->first();
	//     $existUser = $existUser ?? 'no se encontro';
	//     $time_decrypt_get_user = microtime(true) - $time_start_decrypt;

	//     $time_start_only_verificaTokenBD = microtime(true);
	//     $findBD = collect(\DB::select("SELECT * from users where id = 4 "))->first();
	//     $findBD = $findBD ?? 'no se encontro';    
	//     $time_verify_tokenBD = microtime(true) - $time_start_only_verificaTokenBD;


	// 	return response()->json([
	// 		'10_texto'=>$cadena,
	// 		'11_tokenn'=>$token,
	// 		'12_decript'=>$decript_token,
	//         '13_time_only_decript' => $time_only_decrypt,
	//         '14_time_decriptExplode_compare_conSecret' => $time_onlyDecript_explode_compare,

	//         '22_user_encontrado' => $existUser,
	//         '23_tiempo_Decript_And_UserFind' => $time_decrypt_get_user,
	//         '32_Solo Encontrar en BD' => $findBD,
	//         '32_tiempoTokenBD' => $time_verify_tokenBD,
	//         'holamundo' => bcrypt('holamundo'),
	//         'holamundo2' => bcrypt('holamundo'),
	//         'holamundo3' => bcrypt('holamundo'),
	//         'holamundo100' => \Crypt::encrypt('holamundo'),
	//         'holamundo101' => \Crypt::encrypt('holamundo'),
	//         'holamundo103' => \Crypt::encrypt('holamundo'),

	// 	]);
	// }







}