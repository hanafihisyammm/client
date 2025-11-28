import * as React from "react"
import { format, isAfter, startOfDay } from "date-fns"
import { Calendar as CalendarIcon } from "lucide-react"

import { cn } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import { Calendar } from "@/components/ui/calendar"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"

interface DatePickerProps {
  value?: string | Date | null
  onChange: (date: string) => void
  placeholder?: string
  disabled?: boolean
  minDate?: Date
  className?: string
  id?: string
}

export function DatePicker({
  value,
  onChange,
  placeholder = "Pick a date",
  disabled = false,
  minDate,
  className,
  id
}: DatePickerProps) {
  const [date, setDate] = React.useState<Date | undefined>(
    value ? new Date(value) : undefined
  )

  // Default min date to today if not provided
  const minimumDate = minDate || startOfDay(new Date())

  const handleDateSelect = (selectedDate: Date | undefined) => {
    if (selectedDate) {
      // Validate that the selected date is not before minimum date
      if (isAfter(selectedDate, minimumDate) || selectedDate.getTime() === minimumDate.getTime()) {
        setDate(selectedDate)
        onChange(format(selectedDate, "yyyy-MM-dd"))
      }
    } else {
      setDate(undefined)
      onChange("")
    }
  }

  React.useEffect(() => {
    if (value) {
      setDate(new Date(value))
    } else {
      setDate(undefined)
    }
  }, [value])

  return (
    <Popover>
      <PopoverTrigger asChild>
        <Button
          id={id}
          variant="outline"
          className={cn(
            "w-full justify-start text-left font-normal",
            !date && "text-muted-foreground",
            className
          )}
          disabled={disabled}
        >
          <CalendarIcon className="mr-2 h-4 w-4" />
          {date ? format(date, "PPP") : <span>{placeholder}</span>}
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-auto p-0" align="start">
        <Calendar
          mode="single"
          selected={date}
          onSelect={handleDateSelect}
          disabled={(date) => isAfter(minimumDate, date)}
          initialFocus
        />
      </PopoverContent>
    </Popover>
  )
}