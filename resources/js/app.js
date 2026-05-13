function initBookdropUpload(root = document) {
    root.querySelectorAll('[data-bookdrop-upload]').forEach((container) => {
        if (container.dataset.bookdropInitialized === 'true') {
            return
        }

        container.dataset.bookdropInitialized = 'true'

        const dropzone = container.querySelector('[data-bookdrop-dropzone]')
        const input = container.querySelector('[data-bookdrop-input]')
        const queue = container.querySelector('[data-bookdrop-queue]')
        const fileList = container.querySelector('[data-bookdrop-file-list]')
        const progress = container.querySelector('[data-bookdrop-progress]')
        const progressBar = container.querySelector('[data-bookdrop-progress-bar]')
        const progressLabel = container.querySelector('[data-bookdrop-progress-label]')
        const saveButton = container.querySelector('[data-bookdrop-save]')

        if (! dropzone || ! input || ! saveButton) {
            return
        }

        let saving = false

        const setProgress = (value) => {
            const percent = `${value}%`

            progress?.classList.remove('hidden')
            if (progressBar) progressBar.style.width = percent
            if (progressLabel) progressLabel.textContent = percent
        }

        const showFiles = (files) => {
            if (! fileList || ! queue) return

            fileList.replaceChildren()

            Array.from(files).forEach((file) => {
                const item = document.createElement('li')
                item.textContent = file.name
                fileList.appendChild(item)
            })

            queue.classList.toggle('hidden', files.length === 0)
        }

        const selectFiles = (files) => {
            if (! files.length) return

            saving = false
            showFiles(files)
            setProgress(0)
        }

        dropzone.addEventListener('click', () => input.click())
        dropzone.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault()
                input.click()
            }
        })
        dropzone.addEventListener('dragover', (event) => {
            event.preventDefault()
            dropzone.classList.add('border-indigo-500', 'bg-indigo-50')
        })
        dropzone.addEventListener('dragleave', () => {
            dropzone.classList.remove('border-indigo-500', 'bg-indigo-50')
        })
        dropzone.addEventListener('drop', (event) => {
            event.preventDefault()
            dropzone.classList.remove('border-indigo-500', 'bg-indigo-50')

            const files = event.dataTransfer?.files
            if (! files?.length) return

            selectFiles(files)
            input.files = files
            input.dispatchEvent(new Event('change', { bubbles: true }))
        })
        input.addEventListener('change', () => selectFiles(input.files))
        input.addEventListener('livewire-upload-start', () => setProgress(0))
        input.addEventListener('livewire-upload-progress', (event) => setProgress(event.detail.progress))
        input.addEventListener('livewire-upload-error', () => progress?.classList.add('hidden'))
        input.addEventListener('livewire-upload-finish', () => {
            setProgress(100)

            if (saving) return

            saving = true
            setTimeout(() => saveButton.click(), 750)
        })
    })
}

document.addEventListener('DOMContentLoaded', () => initBookdropUpload())
document.addEventListener('livewire:navigated', () => initBookdropUpload())
document.addEventListener('livewire:init', () => initBookdropUpload())
