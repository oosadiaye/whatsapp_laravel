<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Import Contacts') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg"
                 x-data="contactImport()"
                 x-cloak>

                {{-- Step Indicator --}}
                <div class="border-b border-gray-200 px-6 py-4">
                    <nav class="flex items-center justify-center gap-2" aria-label="Progress">
                        <template x-for="(label, index) in steps" :key="index">
                            <div class="flex items-center">
                                <div class="flex items-center gap-2">
                                    <span class="flex items-center justify-center w-8 h-8 rounded-full text-sm font-semibold transition-colors duration-200"
                                          :class="step > index + 1 ? 'bg-[#25D366] text-white' : (step === index + 1 ? 'bg-[#25D366] text-white' : 'bg-gray-200 text-gray-500')">
                                        <template x-if="step > index + 1">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                            </svg>
                                        </template>
                                        <template x-if="step <= index + 1">
                                            <span x-text="index + 1"></span>
                                        </template>
                                    </span>
                                    <span class="text-sm font-medium"
                                          :class="step >= index + 1 ? 'text-gray-900' : 'text-gray-400'"
                                          x-text="label"></span>
                                </div>
                                <template x-if="index < steps.length - 1">
                                    <div class="w-12 h-0.5 mx-2"
                                         :class="step > index + 1 ? 'bg-[#25D366]' : 'bg-gray-200'"></div>
                                </template>
                            </div>
                        </template>
                    </nav>
                </div>

                <form method="POST" action="{{ route('contacts.importProcess') }}" enctype="multipart/form-data" x-ref="importForm">
                    @csrf
                    <input type="hidden" name="group_id" :value="groupId">
                    <input type="hidden" name="column_map[phone]" :value="columnMap.phone">
                    <input type="hidden" name="column_map[name]" :value="columnMap.name">
                    <input type="hidden" name="column_map[custom_field_1]" :value="columnMap.custom_field_1">
                    <input type="hidden" name="column_map[custom_field_2]" :value="columnMap.custom_field_2">

                    <div class="p-6">

                        {{-- Step 1: Source --}}
                        <div x-show="step === 1" x-transition>
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">{{ __('Select Source') }}</h3>

                            {{-- Group selection --}}
                            <div class="mb-6">
                                <label for="group" class="block text-sm font-medium text-gray-700 mb-1">{{ __('Assign to Group') }}</label>
                                <select id="group"
                                        x-model="groupId"
                                        class="w-full rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366] sm:text-sm">
                                    <option value="">{{ __('-- Select a group (optional) --') }}</option>
                                    @foreach($groups as $group)
                                        <option value="{{ $group->id }}">{{ $group->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Input mode toggle --}}
                            <div class="flex gap-3 mb-6">
                                <button type="button"
                                        @click="inputMode = 'file'"
                                        class="flex-1 px-4 py-3 rounded-lg border-2 text-sm font-medium transition-all duration-200"
                                        :class="inputMode === 'file' ? 'border-[#25D366] bg-green-50 text-[#25D366]' : 'border-gray-200 text-gray-500 hover:border-gray-300'">
                                    <svg class="w-6 h-6 mx-auto mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                    </svg>
                                    {{ __('Upload CSV File') }}
                                </button>
                                <button type="button"
                                        @click="inputMode = 'manual'"
                                        class="flex-1 px-4 py-3 rounded-lg border-2 text-sm font-medium transition-all duration-200"
                                        :class="inputMode === 'manual' ? 'border-[#25D366] bg-green-50 text-[#25D366]' : 'border-gray-200 text-gray-500 hover:border-gray-300'">
                                    <svg class="w-6 h-6 mx-auto mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                    {{ __('Paste Manually') }}
                                </button>
                            </div>

                            {{-- File upload zone --}}
                            <div x-show="inputMode === 'file'" x-transition>
                                <div class="border-2 border-dashed rounded-lg p-8 text-center transition-colors duration-200"
                                     :class="dragOver ? 'border-[#25D366] bg-green-50' : 'border-gray-300 hover:border-gray-400'"
                                     @dragover.prevent="dragOver = true"
                                     @dragleave.prevent="dragOver = false"
                                     @drop.prevent="handleFileDrop($event)">
                                    <svg class="w-12 h-12 mx-auto text-gray-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                                    </svg>
                                    <p class="text-sm text-gray-600 mb-2">
                                        {{ __('Drag and drop your CSV file here, or') }}
                                    </p>
                                    <label class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 cursor-pointer transition">
                                        {{ __('Browse Files') }}
                                        <input type="file"
                                               name="file"
                                               accept=".csv,.txt,.xlsx"
                                               class="sr-only"
                                               x-ref="fileInput"
                                               @change="handleFileSelect($event)">
                                    </label>
                                    <template x-if="fileName">
                                        <p class="mt-3 text-sm text-[#25D366] font-medium">
                                            <span x-text="fileName"></span>
                                            <button type="button" @click="clearFile()" class="ml-2 text-red-500 hover:text-red-700">&times;</button>
                                        </p>
                                    </template>
                                </div>
                            </div>

                            {{-- Manual paste --}}
                            <div x-show="inputMode === 'manual'" x-transition>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    {{ __('Paste phone numbers (one per line, optionally comma-separated with name)') }}
                                </label>
                                <textarea name="manual_input"
                                          x-model="manualInput"
                                          rows="8"
                                          placeholder="628123456789, John Doe&#10;628987654321, Jane Smith&#10;628555111222"
                                          class="w-full rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366] sm:text-sm font-mono"></textarea>
                            </div>
                        </div>

                        {{-- Step 2: Column Mapping --}}
                        <div x-show="step === 2" x-transition>
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">{{ __('Map Columns') }}</h3>
                            <p class="text-sm text-gray-500 mb-6">{{ __('Match your CSV columns to the correct contact fields.') }}</p>

                            <template x-if="csvHeaders.length > 0">
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Phone Column') }} <span class="text-red-500">*</span></label>
                                        <select x-model="columnMap.phone"
                                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366] sm:text-sm">
                                            <option value="">{{ __('-- Select column --') }}</option>
                                            <template x-for="(header, idx) in csvHeaders" :key="idx">
                                                <option :value="idx" x-text="header"></option>
                                            </template>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Name Column') }}</label>
                                        <select x-model="columnMap.name"
                                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366] sm:text-sm">
                                            <option value="">{{ __('-- None --') }}</option>
                                            <template x-for="(header, idx) in csvHeaders" :key="idx">
                                                <option :value="idx" x-text="header"></option>
                                            </template>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Custom Field 1') }}</label>
                                        <select x-model="columnMap.custom_field_1"
                                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366] sm:text-sm">
                                            <option value="">{{ __('-- None --') }}</option>
                                            <template x-for="(header, idx) in csvHeaders" :key="idx">
                                                <option :value="idx" x-text="header"></option>
                                            </template>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Custom Field 2') }}</label>
                                        <select x-model="columnMap.custom_field_2"
                                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366] sm:text-sm">
                                            <option value="">{{ __('-- None --') }}</option>
                                            <template x-for="(header, idx) in csvHeaders" :key="idx">
                                                <option :value="idx" x-text="header"></option>
                                            </template>
                                        </select>
                                    </div>
                                </div>
                            </template>
                        </div>

                        {{-- Step 3: Preview --}}
                        <div x-show="step === 3" x-transition>
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">{{ __('Preview') }}</h3>
                            <p class="text-sm text-gray-500 mb-4">{{ __('Showing first 5 rows of your data.') }}</p>

                            <div class="overflow-x-auto border rounded-lg">
                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <template x-for="(header, idx) in csvHeaders" :key="idx">
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase" x-text="header"></th>
                                            </template>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200">
                                        <template x-for="(row, rIdx) in previewRows" :key="rIdx">
                                            <tr class="hover:bg-gray-50">
                                                <template x-for="(cell, cIdx) in row" :key="cIdx">
                                                    <td class="px-4 py-2 text-gray-700 whitespace-nowrap" x-text="cell"></td>
                                                </template>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>

                            <p class="mt-3 text-sm text-gray-500">
                                {{ __('Total rows:') }} <span class="font-semibold" x-text="totalRows"></span>
                            </p>
                        </div>

                        {{-- Step 4: Confirm --}}
                        <div x-show="step === 4" x-transition>
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">{{ __('Confirm Import') }}</h3>

                            <div class="bg-green-50 border border-green-200 rounded-lg p-6">
                                <div class="grid grid-cols-2 gap-4 text-sm">
                                    <div>
                                        <span class="text-gray-500">{{ __('Source:') }}</span>
                                        <span class="font-medium text-gray-900" x-text="inputMode === 'file' ? fileName : '{{ __('Manual input') }}'"></span>
                                    </div>
                                    <div>
                                        <span class="text-gray-500">{{ __('Total contacts:') }}</span>
                                        <span class="font-medium text-gray-900" x-text="totalRows"></span>
                                    </div>
                                    <div>
                                        <span class="text-gray-500">{{ __('Group:') }}</span>
                                        <span class="font-medium text-gray-900" x-text="groupId ? document.querySelector('#group option[value=\'' + groupId + '\']')?.textContent || 'Selected' : '{{ __('None') }}'"></span>
                                    </div>
                                    <div>
                                        <span class="text-gray-500">{{ __('Mode:') }}</span>
                                        <span class="font-medium text-gray-900" x-text="inputMode === 'file' ? '{{ __('CSV Upload') }}' : '{{ __('Manual Paste') }}'"></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>

                    {{-- Navigation Buttons --}}
                    <div class="flex items-center justify-between px-6 py-4 bg-gray-50 border-t border-gray-200">
                        <button type="button"
                                x-show="step > 1"
                                @click="prevStep()"
                                class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 transition ease-in-out duration-150">
                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                            </svg>
                            {{ __('Back') }}
                        </button>
                        <div x-show="step === 1"></div>

                        <div class="flex gap-3">
                            <a href="{{ route('contacts.index') }}"
                               class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 transition ease-in-out duration-150">
                                {{ __('Cancel') }}
                            </a>

                            <button type="button"
                                    x-show="step < 4"
                                    @click="nextStep()"
                                    :disabled="!canProceed()"
                                    class="inline-flex items-center px-4 py-2 bg-[#25D366] border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-[#1da851] disabled:opacity-50 disabled:cursor-not-allowed transition ease-in-out duration-150">
                                {{ __('Next') }}
                                <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                            </button>

                            <button type="submit"
                                    x-show="step === 4"
                                    class="inline-flex items-center px-6 py-2 bg-[#25D366] border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-[#1da851] transition ease-in-out duration-150">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                                </svg>
                                {{ __('Import Contacts') }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        function contactImport() {
            return {
                step: 1,
                steps: ['{{ __("Source") }}', '{{ __("Mapping") }}', '{{ __("Preview") }}', '{{ __("Confirm") }}'],
                inputMode: 'file',
                groupId: '',
                fileName: '',
                fileData: null,
                manualInput: '',
                dragOver: false,
                csvHeaders: [],
                csvRows: [],
                previewRows: [],
                totalRows: 0,
                columnMap: {
                    phone: '',
                    name: '',
                    custom_field_1: '',
                    custom_field_2: '',
                },

                canProceed() {
                    if (this.step === 1) {
                        return this.inputMode === 'file' ? !!this.fileName : this.manualInput.trim().length > 0;
                    }
                    if (this.step === 2) {
                        return this.columnMap.phone !== '';
                    }
                    return true;
                },

                nextStep() {
                    if (!this.canProceed()) return;

                    if (this.step === 1 && this.inputMode === 'manual') {
                        this.parseManualInput();
                        this.step = 4;
                        return;
                    }
                    if (this.step === 1 && this.inputMode === 'file') {
                        this.parseCSV();
                        this.step = 2;
                        return;
                    }
                    if (this.step === 2) {
                        this.buildPreview();
                        this.step = 3;
                        return;
                    }
                    this.step++;
                },

                prevStep() {
                    if (this.step === 4 && this.inputMode === 'manual') {
                        this.step = 1;
                        return;
                    }
                    this.step--;
                },

                handleFileSelect(event) {
                    const file = event.target.files[0];
                    if (file) this.readFile(file);
                },

                handleFileDrop(event) {
                    this.dragOver = false;
                    const file = event.dataTransfer.files[0];
                    if (file) this.readFile(file);
                },

                readFile(file) {
                    this.fileName = file.name;
                    const reader = new FileReader();
                    reader.onload = (e) => {
                        this.fileData = e.target.result;
                    };
                    reader.readAsText(file);
                },

                clearFile() {
                    this.fileName = '';
                    this.fileData = null;
                    this.csvHeaders = [];
                    this.csvRows = [];
                    if (this.$refs.fileInput) {
                        this.$refs.fileInput.value = '';
                    }
                },

                parseCSV() {
                    if (!this.fileData) return;
                    const lines = this.fileData.split(/\r?\n/).filter(line => line.trim());
                    if (lines.length === 0) return;

                    this.csvHeaders = this.splitCSVLine(lines[0]);
                    this.csvRows = [];
                    for (let i = 1; i < lines.length; i++) {
                        this.csvRows.push(this.splitCSVLine(lines[i]));
                    }
                    this.totalRows = this.csvRows.length;

                    // Auto-detect phone and name columns
                    this.csvHeaders.forEach((header, idx) => {
                        const h = header.toLowerCase().trim();
                        if (h.includes('phone') || h.includes('number') || h.includes('wa') || h.includes('mobile')) {
                            this.columnMap.phone = String(idx);
                        }
                        if (h.includes('name') && !h.includes('last') && !h.includes('first')) {
                            this.columnMap.name = String(idx);
                        }
                    });
                },

                splitCSVLine(line) {
                    const result = [];
                    let current = '';
                    let inQuotes = false;
                    for (let i = 0; i < line.length; i++) {
                        const ch = line[i];
                        if (ch === '"') {
                            inQuotes = !inQuotes;
                        } else if (ch === ',' && !inQuotes) {
                            result.push(current.trim());
                            current = '';
                        } else {
                            current += ch;
                        }
                    }
                    result.push(current.trim());
                    return result;
                },

                buildPreview() {
                    this.previewRows = this.csvRows.slice(0, 5);
                },

                parseManualInput() {
                    const lines = this.manualInput.split(/\r?\n/).filter(line => line.trim());
                    this.totalRows = lines.length;
                    this.csvHeaders = ['Phone', 'Name'];
                    this.previewRows = lines.slice(0, 5).map(line => {
                        const parts = line.split(',').map(p => p.trim());
                        return [parts[0] || '', parts[1] || ''];
                    });
                },
            };
        }
    </script>
    @endpush
</x-app-layout>
