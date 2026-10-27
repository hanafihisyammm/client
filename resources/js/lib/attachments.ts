// What an issue may carry: the same types and limits the server checks

export const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp']
export const VIDEO_TYPES = ['video/mp4', 'video/webm', 'video/quicktime']
export const SPREADSHEET_TYPES = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel', 'text/csv']
const SPREADSHEET_EXTENSIONS = ['.xlsx', '.xls', '.csv']

export const ATTACHMENT_ACCEPT = [...IMAGE_TYPES, 'application/pdf', ...VIDEO_TYPES, ...SPREADSHEET_TYPES, ...SPREADSHEET_EXTENSIONS].join(',')

const MAX_FILE_BYTES = 5 * 1024 * 1024
const MAX_VIDEO_BYTES = 25 * 1024 * 1024

export const maxAttachmentBytes = (file: File) => VIDEO_TYPES.includes(file.type) ? MAX_VIDEO_BYTES : MAX_FILE_BYTES

// Systems disagree on a spreadsheet's type (a CSV may even be "Excel"), so its name decides
export const isSpreadsheetFile = (file: { name: string }) => /\.(xlsx|xls|csv)$/i.test(file.name)

/** Whether a picked file may be attached to an issue; the server checks its content again */
export const isAllowedAttachment = (file: File) =>
  file.type.startsWith('image/') || file.type === 'application/pdf' || VIDEO_TYPES.includes(file.type) || isSpreadsheetFile(file)

/** Whether a stored attachment is a video, judged by its saved type, or its name for older records */
export const isVideoAttachment = (attachment: { mime_type?: string; original_filename: string }) =>
  attachment.mime_type ? attachment.mime_type.startsWith('video/') : /\.(mp4|webm|mov)$/i.test(attachment.original_filename)

/** Whether a stored attachment is a spreadsheet; stored files keep their real extension */
export const isSpreadsheetAttachment = (attachment: { original_filename: string }) =>
  isSpreadsheetFile({ name: attachment.original_filename })
