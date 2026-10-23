// What an issue may carry: the same types and limits the server checks

export const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp']
export const VIDEO_TYPES = ['video/mp4', 'video/webm', 'video/quicktime']

export const ATTACHMENT_ACCEPT = [...IMAGE_TYPES, 'application/pdf', ...VIDEO_TYPES].join(',')

const MAX_FILE_BYTES = 5 * 1024 * 1024
const MAX_VIDEO_BYTES = 25 * 1024 * 1024

export const maxAttachmentBytes = (file: File) => VIDEO_TYPES.includes(file.type) ? MAX_VIDEO_BYTES : MAX_FILE_BYTES

/** Whether a stored attachment is a video, judged by its saved type, or its name for older records */
export const isVideoAttachment = (attachment: { mime_type?: string; original_filename: string }) =>
  attachment.mime_type ? attachment.mime_type.startsWith('video/') : /\.(mp4|webm|mov)$/i.test(attachment.original_filename)
