<x-layouts.app :title="__('Dashboard')">
    <input type="file" name="file" />

    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">

        <div class="relative overflow-x-auto shadow-md sm:rounded-lg">
            <table class="w-full text-sm text-left rtl:text-right text-gray-500 dark:text-gray-400">
                <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                    <tr>
                        <th scope="col" class="px-6 py-3">
                            File name
                        </th>
                        <th scope="col" class="px-6 py-3">
                            File path
                        </th>
                        <th scope="col" class="px-6 py-3">
                            File size
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($files as $file)
                        <tr class="odd:bg-white odd:dark:bg-gray-900 even:bg-gray-50 even:dark:bg-gray-800 border-b dark:border-gray-700 border-gray-200">
                            <th scope="row" class="px-6 py-4 font-medium text-gray-900 whitespace-nowrap dark:text-white">
                                {{ $file->file_name }}
                            </th>
                            <td class="px-6 py-4">
                                {{ $file->file_path }}
                            </td>
                            <td class="px-6 py-4">
                                {{ \Illuminate\Support\Number::fileSize($file->file_size) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

    </div>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const inputElement = document.querySelector('input[type="file"]');

                FilePond.create(inputElement, {
                    chunkUploads: true,
                    chunkSize: 1000000, // 1MB chunks
                    server: {
                        url: '{{ route('upload') }}',
                        headers: {
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        }
                    },
                    onprocessfile: (error, file) => {
                        if (!error) {
                            console.log('File processed:', file.file.name);
                            // Refresh the page to show the new file
                            setTimeout(() => {
                                window.location.reload();
                            }, 1000);
                        }
                    },
                });
            });
        </script>
    @endpush
</x-layouts.app>
