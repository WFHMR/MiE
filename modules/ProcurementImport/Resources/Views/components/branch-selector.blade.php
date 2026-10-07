<div class="branch-selector mb-4" x-data="branchSelector()">
    <div class="flex items-center gap-4">
        <label class="font-semibold text-gray-700">เลือกสาขา:</label>
        
        <select 
            x-model="selectedBranch" 
            @change="changeBranch()"
            class="form-select rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200"
        >
            <option value="">-- เลือกสาขา --</option>
            <template x-for="branch in branches" :key="branch.id">
                <option :value="branch.id" x-text="`${branch.name} (${branch.code})`"></option>
            </template>
        </select>
        
        <span x-show="loading" class="text-sm text-gray-500">
            <i class="fas fa-spinner fa-spin"></i> กำลังโหลด...
        </span>
        
        <span x-show="success" class="text-sm text-green-600">
            <i class="fas fa-check-circle"></i> เลือกสาขาเรียบร้อย
        </span>
    </div>
</div>

<script>
function branchSelector() {
    return {
        branches: [],
        selectedBranch: '',
        loading: false,
        success: false,
        
        init() {
            this.loadBranches();
            this.loadCurrentBranch();
        },
        
        async loadBranches() {
            try {
                const response = await fetch('/api/procurement-import/branches');
                const data = await response.json();
                this.branches = data;
            } catch (error) {
                console.error('Failed to load branches:', error);
            }
        },
        
        async loadCurrentBranch() {
            try {
                const response = await fetch('/api/procurement-import/branches/current');
                const data = await response.json();
                this.selectedBranch = data.id;
            } catch (error) {
                console.error('Failed to load current branch:', error);
            }
        },
        
        async changeBranch() {
            if (!this.selectedBranch) return;
            
            this.loading = true;
            this.success = false;
            
            try {
                const response = await fetch('/api/procurement-import/branches/set-current', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({
                        branch_id: this.selectedBranch
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    this.success = true;
                    setTimeout(() => {
                        this.success = false;
                        // Reload page to apply branch filter
                        window.location.reload();
                    }, 1000);
                }
            } catch (error) {
                console.error('Failed to set branch:', error);
                alert('เกิดข้อผิดพลาดในการเลือกสาขา');
            } finally {
                this.loading = false;
            }
        }
    }
}
</script>
