@extends('layouts.master')
@section('content')
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3>Import CSV</h3>
            <button type="button" class="btn btn-danger" onclick="confirmReset()">
                Reset Database
            </button>
        </div>
        <div class="card-body">
            @if(session('flash_message'))
                <div class="alert alert-success">
                    {{ session('flash_message') }}
                </div>
            @endif

            @if(session('flash_message_warning'))
                <div class="alert alert-danger">
                    {{ session('flash_message_warning') }}
                </div>
            @endif

            <form action="{{ url('/csv') }}" method="POST" enctype="multipart/form-data" id="importForm">
                @csrf
                <div class="form-group mt-3">
                    <label for="csv_file">CSV File</label>
                    <input type="file" name="csv_file" id="csv_file" class="form-control" accept=".csv,.txt" required>
                </div>

                <div id="fileAnalysis" class="mt-3" style="display: none;">
                    <div class="alert alert-info">
                        <h5>CSV File Analysis:</h5>
                        <div id="csvHeaders" class="small"></div>
                        <div id="csvPreview" class="small mt-2"></div>
                    </div>
                </div>

                <div id="suggestions" class="mt-3" style="display: none;">
                    <div class="alert alert-info">
                        <h5>Suggested Tables Based on Column Matching:</h5>
                        <div id="suggestionsList"></div>
                    </div>
                </div>

                <div class="form-group mt-3">
                    <label for="table_name">Select Table</label>
                    <select name="table_name" id="table_name" class="form-control" required>
                        <option value="">Select a table</option>
                        @foreach($tables as $table)
                            <option value="{{ $table['name'] }}">{{ $table['name'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="mt-3">
                    <button type="submit" class="btn btn-primary">Import CSV</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div class="modal fade" id="confirmationModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Database Reset</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <strong>Warning!</strong> This will reset the entire database to its initial state.
                        All existing data will be lost and replaced with seed data.
                    </div>
                    <p>Are you sure you want to continue?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="resetDatabase()">Reset Database</button>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        document.getElementById('csv_file').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;

            const formData = new FormData();
            formData.append('csv_file', file);
            formData.append('_token', '{{ csrf_token() }}');

            // Show loading state
            document.getElementById('fileAnalysis').style.display = 'block';
            document.getElementById('csvHeaders').innerHTML = '<div class="text-center">Analyzing file...</div>';
            document.getElementById('suggestions').style.display = 'none';

            fetch('{{ url('/csv/analyze') }}', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                // Display CSV Analysis
                const fileAnalysis = document.getElementById('fileAnalysis');
                const csvHeaders = document.getElementById('csvHeaders');
                const csvPreview = document.getElementById('csvPreview');
                
                csvHeaders.innerHTML = `
                    <strong>Headers Found:</strong><br>
                    ${data.headers.join(', ')}
                `;
                
                if (data.sample_data) {
                    csvPreview.innerHTML = `
                        <strong>Sample Data:</strong><br>
                        ${data.sample_data.join(', ')}
                    `;
                }
                
                fileAnalysis.style.display = 'block';

                // Display Suggestions
                const suggestionsDiv = document.getElementById('suggestions');
                const suggestionsList = document.getElementById('suggestionsList');
                suggestionsList.innerHTML = '';

                if (Object.keys(data.suggestions).length > 0) {
                    Object.entries(data.suggestions).forEach(([tableName, info]) => {
                        const suggestion = document.createElement('div');
                        suggestion.className = 'mb-3 p-3 border rounded';
                        
                        let mappingHtml = '';
                        if (info.sample_mapping) {
                            mappingHtml = '<div class="mt-2"><strong>Column Mapping:</strong><br>';
                            Object.entries(info.sample_mapping).forEach(([header, mapping]) => {
                                const badgeClass = mapping.type === 'exact' ? 'success' : 
                                                (mapping.type === 'similar' ? 'warning' : 'danger');
                                mappingHtml += `
                                    <div class="mb-1">
                                        <span class="badge badge-${badgeClass}">${mapping.type}</span>
                                        ${header} ${mapping.type !== 'missing' ? '→ ' + mapping.maps_to : '(no match)'}
                                        <small class="text-muted">(Sample: ${mapping.sample || 'empty'})</small>
                                    </div>`;
                            });
                            mappingHtml += '</div>';
                        }

                        suggestion.innerHTML = `
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-0">${tableName}</h6>
                                    <div class="progress mt-1" style="height: 5px; width: 200px;">
                                        <div class="progress-bar bg-success" role="progressbar" 
                                             style="width: ${info.score}%" 
                                             aria-valuenow="${info.score}" 
                                             aria-valuemin="0" 
                                             aria-valuemax="100">
                                        </div>
                                    </div>
                                    <small class="text-muted">${info.score.toFixed(1)}% match</small>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectTable('${tableName}')">
                                    Select
                                </button>
                            </div>
                            ${mappingHtml}
                        `;
                        suggestionsList.appendChild(suggestion);
                    });
                    suggestionsDiv.style.display = 'block';
                } else {
                    suggestionsList.innerHTML = '<div class="alert alert-warning">No matching tables found for the CSV structure.</div>';
                    suggestionsDiv.style.display = 'block';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                const suggestionsDiv = document.getElementById('suggestions');
                const suggestionsList = document.getElementById('suggestionsList');
                suggestionsList.innerHTML = '<div class="alert alert-danger">Error analyzing CSV file.</div>';
                suggestionsDiv.style.display = 'block';
            });
        });

        function selectTable(tableName) {
            document.getElementById('table_name').value = tableName;
        }

        function confirmReset() {
            $('#confirmationModal').modal('show');
        }

        function resetDatabase() {
            fetch('{{ url('/csv/truncate-all') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                }
            })
            .then(response => response.json())
            .then(data => {
                $('#confirmationModal').modal('hide');
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while resetting the database.');
            });
        }
    </script>
    @endpush
@endsection
