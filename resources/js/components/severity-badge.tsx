import { Badge } from '@/components/ui/badge'

interface SeverityBadgeProps {
  severity: 'critical' | 'major' | 'minor' | 'trivial'
  className?: string
}

const severityConfig = {
  critical: {
    label: 'Critical',
    className: 'bg-red-600 text-white hover:bg-red-700',
    description: 'System down, data loss'
  },
  major: {
    label: 'Major',
    className: 'bg-orange-500 text-black hover:bg-orange-400',
    description: 'Feature broken, no workaround'
  },
  minor: {
    label: 'Minor',
    className: 'bg-yellow-500 text-black hover:bg-yellow-400',
    description: 'Feature broken, has workaround'
  },
  trivial: {
    label: 'Trivial',
    className: 'bg-gray-500 text-white hover:bg-gray-400',
    description: 'Cosmetic issue'
  }
}

export function SeverityBadge({ severity, className = '' }: SeverityBadgeProps) {
  const config = severityConfig[severity] || severityConfig.minor

  return (
    <Badge className={`${config.className} ${className}`} title={config.description}>
      {config.label}
    </Badge>
  )
}

export { severityConfig }
