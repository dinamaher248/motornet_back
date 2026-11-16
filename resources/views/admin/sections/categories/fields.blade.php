@extends('admin.layout.admin_app')

@section('content')
<div class="container-fluid">
  <h2 class="mb-1">حقول نموذج الحجز — {{ $category->name }}</h2>
  <p class="text-muted">هذه الحقول تظهر للعميل في تطبيق الجوال قبل زر «إرسال لمعرفة السعر والتفاصيل». أي تعديل هنا يظهر مباشرة بدون تعديل في الكود.</p>

  @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
  @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
  @if($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
  @endif

  <a href="{{ route('admin.categories.index') }}" class="btn btn-secondary mb-3">← رجوع للأقسام</a>

  <div class="card mb-4"><div class="card-body">
    <h5 class="mb-3">+ إضافة حقل جديد</h5>
    <form method="POST" action="{{ route('admin.categories.fields.store', $category) }}" class="row g-2 field-form">
      @csrf
      <div class="col-md-4"><input name="label" class="form-control" placeholder="عنوان الحقل (مثال: عدد الأفراد)" required value="{{ old('label') }}"></div>
      <div class="col-md-3">
        <select name="field_type" class="form-select field-type" required>
          @foreach($types as $key => $label)<option value="{{ $key }}" @selected(old('field_type') === $key)>{{ $label }}</option>@endforeach
        </select>
      </div>
      <div class="col-md-2 d-flex align-items-center">
        <div class="form-check"><input type="checkbox" class="form-check-input" name="is_required" value="1" id="new_req" @checked(old('is_required'))><label class="form-check-label" for="new_req">إجباري</label></div>
      </div>
      <div class="col-md-3"><button class="btn btn-primary w-100">إضافة</button></div>
      <div class="col-12 options-box" style="display:none">
        <textarea name="options_text" class="form-control" rows="3" placeholder="الخيارات — كل خيار في سطر">{{ old('options_text') }}</textarea>
      </div>
    </form>
  </div></div>

  <div class="card"><div class="card-body">
    <h5 class="mb-3">الحقول الحالية ({{ $fields->count() }})</h5>
    @if($fields->isEmpty())
      <p class="text-muted mb-0">لا توجد حقول بعد.</p>
    @else
    <table class="table table-bordered align-middle">
      <thead><tr><th style="width:90px">الترتيب</th><th>العنوان</th><th>النوع</th><th>الخيارات</th><th>إجباري؟</th><th style="width:230px">إجراءات</th></tr></thead>
      <tbody>
      @foreach($fields as $i => $f)
        <tr>
          <td class="text-nowrap">
            <form method="POST" action="{{ route('admin.categories.fields.move', [$category, $f]) }}" class="d-inline">@csrf<input type="hidden" name="direction" value="up"><button class="btn btn-sm btn-outline-secondary" @disabled($i === 0)>▲</button></form>
            <form method="POST" action="{{ route('admin.categories.fields.move', [$category, $f]) }}" class="d-inline">@csrf<input type="hidden" name="direction" value="down"><button class="btn btn-sm btn-outline-secondary" @disabled($i === $fields->count() - 1)>▼</button></form>
          </td>
          <td>{{ $f->label }}</td>
          <td><span class="badge bg-info">{{ $types[$f->field_type] ?? $f->field_type }}</span></td>
          <td>{{ $f->hasOptions() ? implode(' ، ', $f->options ?? []) : '—' }}</td>
          <td><span class="badge {{ $f->is_required ? 'bg-danger' : 'bg-secondary' }}">{{ $f->is_required ? 'إجباري' : 'اختياري' }}</span></td>
          <td class="text-nowrap">
            <button class="btn btn-sm btn-info" type="button" data-bs-toggle="collapse" data-bs-target="#edit{{ $f->id }}">تعديل</button>
            <form method="POST" action="{{ route('admin.categories.fields.destroy', [$category, $f]) }}" class="d-inline" onsubmit="return confirm('حذف الحقل؟')">@csrf @method('DELETE')<button class="btn btn-sm btn-danger">حذف</button></form>
          </td>
        </tr>
        <tr class="collapse" id="edit{{ $f->id }}">
          <td colspan="6">
            <form method="POST" action="{{ route('admin.categories.fields.update', [$category, $f]) }}" class="row g-2 field-form">
              @csrf @method('PUT')
              <div class="col-md-4"><input name="label" class="form-control" required value="{{ $f->label }}"></div>
              <div class="col-md-3">
                <select name="field_type" class="form-select field-type">
                  @foreach($types as $key => $label)<option value="{{ $key }}" @selected($f->field_type === $key)>{{ $label }}</option>@endforeach
                </select>
              </div>
              <div class="col-md-2 d-flex align-items-center">
                <div class="form-check"><input type="checkbox" class="form-check-input" name="is_required" value="1" id="req{{ $f->id }}" @checked($f->is_required)><label class="form-check-label" for="req{{ $f->id }}">إجباري</label></div>
              </div>
              <div class="col-md-3"><button class="btn btn-success w-100">حفظ</button></div>
              <div class="col-12 options-box" style="display:none">
                <textarea name="options_text" class="form-control" rows="3" placeholder="الخيارات — كل خيار في سطر">{{ implode("\n", $f->options ?? []) }}</textarea>
              </div>
            </form>
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
    @endif
  </div></div>
</div>

<script>
  // إظهار خانة الخيارات فقط لنوعي select و radio
  document.querySelectorAll('.field-form').forEach(function (form) {
    var type = form.querySelector('.field-type');
    var box = form.querySelector('.options-box');
    function sync() { box.style.display = (type.value === 'select' || type.value === 'radio') ? '' : 'none'; }
    type.addEventListener('change', sync);
    sync();
  });
</script>
@endsection
