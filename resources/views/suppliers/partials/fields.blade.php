<div>
    <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="name">Name</label>
    <input type="text" name="name" id="name" value="{{ old('name', $supplier->name ?? '') }}" required class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
</div>
<div>
    <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="contact_person">Contact person</label>
    <input type="text" name="contact_person" id="contact_person" value="{{ old('contact_person', $supplier->contact_person ?? '') }}" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
</div>
<div>
    <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="phone">Phone</label>
    <input type="text" name="phone" id="phone" value="{{ old('phone', $supplier->phone ?? '') }}" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
</div>
<div>
    <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="location">Location</label>
    <input type="text" name="location" id="location" value="{{ old('location', $supplier->location ?? '') }}" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
</div>
<div>
    <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="note">Note</label>
    <input type="text" name="note" id="note" value="{{ old('note', $supplier->note ?? '') }}" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
</div>