<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Models\ReservationCategoryField;
use App\Models\ServiceCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReservationFieldAdminController extends Controller
{
    public function index(ServiceCategory $category)
    {
        $this->ensureBookingsCategory($category);

        $fields = $category->reservationFields()->get();

        return view('admin.sections.categories.fields', [
            'category' => $category,
            'fields' => $fields,
            'types' => ReservationCategoryField::TYPE_LABELS,
        ]);
    }

    public function store(Request $request, ServiceCategory $category)
    {
        $this->ensureBookingsCategory($category);

        $data = $this->validated($request);
        $data['category_id'] = $category->id;
        $data['sort_order'] = ((int) $category->reservationFields()->max('sort_order')) + 1;

        ReservationCategoryField::create($data);

        return back()->with('success', 'تم إضافة الحقل');
    }

    public function update(Request $request, ServiceCategory $category, ReservationCategoryField $field)
    {
        $this->ensureBookingsCategory($category);
        $this->ensureFieldBelongs($category, $field);

        $field->update($this->validated($request));

        return back()->with('success', 'تم تعديل الحقل');
    }

    public function destroy(ServiceCategory $category, ReservationCategoryField $field)
    {
        $this->ensureBookingsCategory($category);
        $this->ensureFieldBelongs($category, $field);

        $field->delete();

        return back()->with('success', 'تم حذف الحقل');
    }

    /** تحريك الحقل لأعلى أو لأسفل (direction = up | down). */
    public function move(Request $request, ServiceCategory $category, ReservationCategoryField $field)
    {
        $this->ensureBookingsCategory($category);
        $this->ensureFieldBelongs($category, $field);

        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];

        $fields = $category->reservationFields()->get()->values();
        $index = $fields->search(fn (ReservationCategoryField $f) => $f->id === $field->id);
        $swapWith = $direction === 'up' ? $index - 1 : $index + 1;

        if ($index === false || $swapWith < 0 || $swapWith >= $fields->count()) {
            return back();
        }

        $ids = $fields->pluck('id')->all();
        [$ids[$index], $ids[$swapWith]] = [$ids[$swapWith], $ids[$index]];
        $this->saveOrder($ids);

        return back();
    }

    /** حفظ ترتيب كامل: order[] = [fieldId, fieldId, ...] (للسحب والإفلات). */
    public function reorder(Request $request, ServiceCategory $category)
    {
        $this->ensureBookingsCategory($category);

        $data = $request->validate([
            'order' => 'required|array|min:1',
            'order.*' => 'integer',
        ]);

        $valid = $category->reservationFields()->pluck('id')->all();
        $ids = array_values(array_filter(
            array_map('intval', $data['order']),
            fn ($id) => in_array($id, $valid, true)
        ));
        // أي حقل لم يُرسل يبقى في آخر القائمة بترتيبه الحالي
        $ids = array_merge($ids, array_values(array_diff($valid, $ids)));
        $this->saveOrder($ids);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'تم حفظ الترتيب');
    }

    private function saveOrder(array $ids): void
    {
        foreach ($ids as $position => $id) {
            ReservationCategoryField::whereKey($id)->update(['sort_order' => $position + 1]);
        }
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'label' => 'required|string|max:150',
            'field_type' => ['required', Rule::in(ReservationCategoryField::TYPES)],
            'options_text' => 'nullable|string|max:2000',
            'is_required' => 'nullable|boolean',
        ]);

        $options = null;
        if (in_array($data['field_type'], ['select', 'radio'], true)) {
            $options = collect(preg_split('/\r\n|\r|\n/', (string) ($data['options_text'] ?? '')))
                ->map(fn ($line) => trim((string) $line))
                ->filter(fn ($line) => $line !== '')
                ->unique()
                ->values()
                ->all();

            if (count($options) < 2) {
                abort(back()->withInput()->withErrors([
                    'options_text' => 'اكتب خيارين على الأقل (كل خيار في سطر)',
                ]));
            }
        }

        return [
            'label' => trim($data['label']),
            'field_type' => $data['field_type'],
            'options' => $options,
            'is_required' => $request->boolean('is_required'),
        ];
    }

    private function ensureBookingsCategory(ServiceCategory $category): void
    {
        abort_unless($category->billingSection() === 'bookings', 404, 'هذا القسم ليس من أقسام الحجوزات');
    }

    private function ensureFieldBelongs(ServiceCategory $category, ReservationCategoryField $field): void
    {
        abort_unless((int) $field->category_id === (int) $category->id, 404);
    }
}
