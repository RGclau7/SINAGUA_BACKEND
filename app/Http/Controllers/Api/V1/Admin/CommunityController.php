<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Community;
use App\Models\Roles;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\PersonData;
use App\Tenancy\CurrentCommunity;
use App\Users\UserChangePublisher;
use App\Users\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

/**
 * Solo superadministrador: alta de comunidades y de su primer administrador.
 * No expone movimientos de ninguna comunidad.
 */
class CommunityController extends Controller
{
    public function __construct(
        private readonly CurrentCommunity $context,
        private readonly UserChangePublisher $publisher,
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::ok(
            Community::query()->orderBy('name')->get()->map(fn (Community $c) => $this->present($c))->all()
        );
    }

    /**
     * Alta de comunidad con sus datos de ubicación y contacto, y su primer administrador
     * con registro completo.
     */
    public function store(Request $request): JsonResponse
    {
        $input = $request->all();
        $input['contact_phone'] = PersonData::normalizePhone($input['contact_phone'] ?? null);
        $input['admin'] = PersonData::prepare((array) ($input['admin'] ?? []));
        if (isset($input['admin']['email']) && is_string($input['admin']['email'])) {
            $input['admin']['email'] = mb_strtolower(trim($input['admin']['email']));
        }

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:160', 'unique:communities,name'],
            'state' => ['required', 'string', 'max:80'],
            'municipality' => ['required', 'string', 'max:120'],
            'locality' => ['required', 'string', 'min:3', 'max:255'],
            'contact_phone' => ['required', 'string', 'regex:'.PersonData::PHONE_PATTERN],
            'contact_email' => ['nullable', 'email', 'max:190'],
            ...PersonData::rules(true, 'admin.'),
            'admin.email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'admin.password' => ['required', Password::min(10)],
        ], [
            'name.required' => 'El nombre de la comunidad es obligatorio.',
            'name.unique' => 'Ya existe una comunidad con ese nombre.',
            'state.required' => 'El estado es obligatorio.',
            'municipality.required' => 'El municipio es obligatorio.',
            'locality.required' => 'La localidad o dirección es obligatoria.',
            'contact_phone.required' => 'El teléfono de contacto es obligatorio.',
            'contact_phone.regex' => 'El teléfono de contacto debe tener 10 dígitos.',
            ...PersonData::messages('admin.'),
            'admin.email.required' => 'El correo del administrador es obligatorio.',
            'admin.email.unique' => 'Ese correo ya está registrado.',
            'admin.password.required' => 'La contraseña del administrador es obligatoria.',
        ])->validate();

        [$community, $admin] = DB::transaction(fn () => $this->context->runAsSystem(function () use ($data, $request) {
            $community = Community::create([
                'name' => trim($data['name']),
                'state' => trim($data['state']),
                'municipality' => trim($data['municipality']),
                'locality' => trim($data['locality']),
                'contact_phone' => $data['contact_phone'],
                'contact_email' => $data['contact_email'] ?? null,
            ]);

            $a = $data['admin'];
            $admin = new User([
                'given_names' => $a['given_names'],
                'paternal_surname' => $a['paternal_surname'],
                'maternal_surname' => $a['maternal_surname'] ?? null,
                'phone' => $a['phone'],
                'address' => $a['address'],
                'email' => $a['email'],
                'password' => $a['password'],
            ]);
            $admin->name = PersonData::fullName($a['given_names'], $a['paternal_surname'], $a['maternal_surname'] ?? null);
            $admin->community_id = $community->id;
            $admin->save();
            $admin->assignRole(Role::findOrCreate(Roles::ADMIN, 'web'));

            AuditLog::record('community.created', [
                'community_id' => $community->id,
                'user_id' => $request->user()->id,
                'entity' => 'communities',
                'entity_id' => $community->id,
                'data' => ['name' => $community->name, 'admin_id' => $admin->id, 'admin_name' => $admin->name, 'admin_email' => $admin->email],
            ]);

            // Para que los equipos de la comunidad lo reciban en su primera sincronización.
            $this->publisher->publish($admin, 'create');

            return [$community, $admin];
        }));

        return ApiResponse::ok([
            ...$this->present($community),
            'admin' => UserService::present($admin->fresh()),
        ], 201);
    }

    private function present(Community $community): array
    {
        return [
            'id' => $community->id,
            'name' => $community->name,
            'state' => $community->state,
            'municipality' => $community->municipality,
            'locality' => $community->locality,
            'contact_phone' => $community->contact_phone,
            'contact_email' => $community->contact_email,
            'is_active' => $community->is_active,
            'created_at' => $community->created_at?->toIso8601String(),
        ];
    }
}
