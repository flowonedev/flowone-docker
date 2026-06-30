import { ref, computed } from 'vue'
import automationHubApi from '../services/automationHubApi'

const CATEGORY_COLORS = {
  trigger: { bg: 'bg-amber-500/20', border: 'border-amber-500', text: 'text-amber-400', accent: '#f59e0b', dot: 'bg-amber-500' },
  action: { bg: 'bg-blue-500/20', border: 'border-blue-500', text: 'text-blue-400', accent: '#3b82f6', dot: 'bg-blue-500' },
  logic: { bg: 'bg-emerald-500/20', border: 'border-emerald-500', text: 'text-emerald-400', accent: '#10b981', dot: 'bg-emerald-500' },
}

const VARIABLE_DOCS = {
  'trigger.board.card_moved': [
    { var: '{board_name}', desc: 'Board name' },
    { var: '{card_id}', desc: 'Card ID' },
    { var: '{card_title}', desc: 'Card title' },
    { var: '{list_name}', desc: 'Source list name' },
    { var: '{to_list}', desc: 'Destination list name' },
    { var: '{due_date}', desc: 'Due date' },
    { var: '{assigned_to}', desc: 'Assigned user' },
    { var: '{user_email}', desc: 'User who moved the card' },
  ],
  'trigger.board.card_created': [
    { var: '{board_name}', desc: 'Board name' },
    { var: '{card_id}', desc: 'Card ID' },
    { var: '{card_title}', desc: 'Card title' },
    { var: '{list_name}', desc: 'List name' },
    { var: '{created_by}', desc: 'Created by (email)' },
    { var: '{assigned_to}', desc: 'Assigned user' },
    { var: '{user_email}', desc: 'User who created the card' },
  ],
  'trigger.board.card_completed': [
    { var: '{board_name}', desc: 'Board name' },
    { var: '{card_id}', desc: 'Card ID' },
    { var: '{card_title}', desc: 'Card title' },
    { var: '{list_name}', desc: 'Source list name' },
    { var: '{to_list}', desc: 'Destination list name' },
    { var: '{due_date}', desc: 'Due date' },
    { var: '{assigned_to}', desc: 'Assigned user' },
    { var: '{user_email}', desc: 'User who completed the card' },
  ],
  'trigger.board.card_overdue': [
    { var: '{board_name}', desc: 'Board name' },
    { var: '{card_id}', desc: 'Card ID' },
    { var: '{card_title}', desc: 'Card title' },
    { var: '{list_name}', desc: 'Current list name' },
    { var: '{to_list}', desc: 'Target list name' },
    { var: '{due_date}', desc: 'Due date' },
    { var: '{assigned_to}', desc: 'Assigned user' },
    { var: '{user_email}', desc: 'Card assignee' },
  ],
  'trigger.crm.deal_stage_changed': [
    { var: '{deal_id}', desc: 'Deal ID' },
    { var: '{deal_title}', desc: 'Deal title' },
    { var: '{from_stage}', desc: 'Previous stage' },
    { var: '{to_stage}', desc: 'New stage' },
    { var: '{expected_value}', desc: 'Deal value' },
    { var: '{lost_reason}', desc: 'Lost reason (if applicable)' },
    { var: '{pipeline_name}', desc: 'Pipeline name' },
    { var: '{user_email}', desc: 'User who moved the deal' },
  ],
  'trigger.crm.deal_won': [
    { var: '{deal_id}', desc: 'Deal ID' },
    { var: '{deal_title}', desc: 'Deal title' },
    { var: '{expected_value}', desc: 'Deal value' },
    { var: '{from_stage}', desc: 'Previous stage' },
    { var: '{to_stage}', desc: 'Final stage' },
    { var: '{pipeline_name}', desc: 'Pipeline name' },
    { var: '{user_email}', desc: 'Deal owner' },
  ],
  'trigger.crm.deal_lost': [
    { var: '{deal_id}', desc: 'Deal ID' },
    { var: '{deal_title}', desc: 'Deal title' },
    { var: '{expected_value}', desc: 'Deal value' },
    { var: '{lost_reason}', desc: 'Reason for losing' },
    { var: '{from_stage}', desc: 'Stage when lost' },
    { var: '{to_stage}', desc: 'Target stage' },
    { var: '{pipeline_name}', desc: 'Pipeline name' },
    { var: '{user_email}', desc: 'Deal owner' },
  ],
  'trigger.crm.invoice_overdue': [
    { var: '{invoice_id}', desc: 'Invoice ID' },
    { var: '{invoice_number}', desc: 'Invoice number' },
    { var: '{amount}', desc: 'Invoice amount' },
    { var: '{due_date}', desc: 'Due date' },
    { var: '{days_overdue}', desc: 'Days overdue' },
    { var: '{client_name}', desc: 'Client name' },
    { var: '{user_email}', desc: 'Invoice owner' },
  ],
  'trigger.server.health': [
    { var: '{metric}', desc: 'Metric name (cpu_load, memory_usage, etc.)' },
    { var: '{value}', desc: 'Current metric value' },
    { var: '{threshold}', desc: 'Configured threshold' },
    { var: '{service}', desc: 'Service name (if service_status metric)' },
    { var: '{status}', desc: 'Service status (running/stopped)' },
    { var: '{hostname}', desc: 'Server hostname' },
  ],
  'trigger.manual': [
    { var: '{triggered_at}', desc: 'Execution timestamp' },
    { var: '{user_email}', desc: 'Workflow owner email' },
    { var: '{workflow_id}', desc: 'Workflow ID' },
  ],
  'trigger.schedule.cron': [
    { var: '{scheduled_at}', desc: 'Scheduled execution timestamp' },
    { var: '{schedule_type}', desc: 'Schedule type (interval/cron)' },
    { var: '{user_email}', desc: 'Workflow owner email' },
    { var: '{workflow_id}', desc: 'Workflow ID' },
  ],
  'trigger.webhook.incoming': [
    { var: '{webhook_body.*}', desc: 'Any field from the webhook JSON body' },
    { var: '{webhook_headers.*}', desc: 'Any HTTP header from the request' },
    { var: '{webhook_method}', desc: 'HTTP method (GET, POST, etc.)' },
  ],
  'trigger.telegram.message': [
    { var: '{telegram_text}', desc: 'Message text' },
    { var: '{telegram_chat_id}', desc: 'Chat ID' },
    { var: '{telegram_user}', desc: 'Sender username' },
  ],
  'trigger.client.health_low': [
    { var: '{client_id}', desc: 'Client ID' },
    { var: '{client_name}', desc: 'Client display name' },
    { var: '{health_score}', desc: 'Current health score (0-100)' },
    { var: '{days_since_activity}', desc: 'Days since last activity' },
  ],
  'trigger.client.inactive': [
    { var: '{client_id}', desc: 'Client ID' },
    { var: '{client_name}', desc: 'Client display name' },
    { var: '{days_inactive}', desc: 'Number of days since last activity' },
  ],
  'trigger.invoice.paid': [
    { var: '{invoice_id}', desc: 'Invoice ID' },
    { var: '{invoice_number}', desc: 'Invoice number (e.g. INV-2026-001)' },
    { var: '{client_name}', desc: 'Client name' },
    { var: '{paid_amount}', desc: 'Paid amount' },
    { var: '{currency}', desc: 'Currency code' },
  ],
  'trigger.invoice.created': [
    { var: '{invoice_id}', desc: 'Invoice ID' },
    { var: '{invoice_number}', desc: 'Invoice number' },
    { var: '{client_name}', desc: 'Client name' },
    { var: '{total}', desc: 'Invoice total' },
    { var: '{currency}', desc: 'Currency code' },
  ],
  'trigger.financial.threshold': [
    { var: '{metric}', desc: 'Metric (revenue, expenses, outstanding)' },
    { var: '{value}', desc: 'Current metric value' },
    { var: '{threshold}', desc: 'Configured threshold' },
    { var: '{period}', desc: 'Evaluation period' },
    { var: '{currency}', desc: 'Currency code' },
  ],
  'action.email.send': [
    { var: '{user_email}', desc: 'Authenticated user email' },
    { var: '{files_list}', desc: 'Formatted file list (numbered, with sizes)' },
    { var: '{files_names}', desc: 'Comma-separated file names' },
    { var: '{events_list}', desc: 'Formatted event list (numbered, with times)' },
    { var: '{contacts_list}', desc: 'Formatted contact list (numbered)' },
    { var: 'All upstream vars', desc: 'Every variable from the trigger/previous nodes' },
  ],
  'action.chat.send': [
    { var: '{user_email}', desc: 'Authenticated user email' },
    { var: '{files_list}', desc: 'Formatted file list (numbered, with sizes)' },
    { var: '{files_names}', desc: 'Comma-separated file names' },
    { var: '{files_count}', desc: 'Number of files' },
    { var: '{events_list}', desc: 'Formatted event list (numbered, with times)' },
    { var: '{contacts_list}', desc: 'Formatted contact list (numbered)' },
    { var: '{rankings_list}', desc: 'Formatted client ranking list' },
    { var: '{invoices_list}', desc: 'Formatted overdue invoices list' },
    { var: 'All upstream vars', desc: 'Every variable from the trigger/previous nodes' },
  ],
  'action.notification.send': [
    { var: '{user_email}', desc: 'Authenticated user email' },
    { var: 'All upstream vars', desc: 'Every variable from the trigger/previous nodes' },
  ],
  'action.telegram.send': [
    { var: '{telegram_chat_id}', desc: 'Chat ID (auto-filled from trigger)' },
    { var: 'All upstream vars', desc: 'Every variable from the trigger/previous nodes' },
  ],
  'action.http.request': [
    { var: '{status_code}', desc: 'HTTP response status code' },
    { var: '{response}', desc: 'Response body (JSON-decoded if possible)' },
    { var: 'All upstream vars', desc: 'Use in URL, headers, or body' },
  ],
  'action.crm.move_deal': [
    { var: '{deal_id}', desc: 'Deal ID (auto-filled from trigger)' },
    { var: 'All upstream vars', desc: 'Every variable from the trigger/previous nodes' },
  ],
  'action.board.move_card': [
    { var: '{card_id}', desc: 'Card ID (auto-filled from trigger)' },
    { var: 'All upstream vars', desc: 'Every variable from the trigger/previous nodes' },
  ],
  'action.task.create': [
    { var: 'All upstream vars', desc: 'Use in title, description, or assignee fields' },
  ],
  'action.export.csv': [
    { var: '{filename}', desc: 'Export filename' },
    { var: '{download_url}', desc: 'Download URL for the CSV' },
    { var: '{drive_file_id}', desc: 'Drive file ID' },
    { var: '{drive_url}', desc: 'Drive file path' },
    { var: '{folder_name}', desc: 'Drive folder (CSV Exports)' },
    { var: '{row_count}', desc: 'Number of exported rows' },
    { var: '{file_size}', desc: 'File size in bytes' },
    { var: '{workflow_name}', desc: 'Workflow name used in filename' },
    { var: 'All upstream vars', desc: 'Use in filename or notify_email' },
  ],
  'action.client.get_data': [
    { var: '{client_id}', desc: 'Client ID' },
    { var: '{client_name}', desc: 'Client display name' },
    { var: '{client_domain}', desc: 'Client domain' },
    { var: '{client_status}', desc: 'Client status (active/waiting/attention)' },
    { var: '{contacts}', desc: 'Array of client contacts' },
    { var: '{open_task_count}', desc: 'Number of open tasks' },
    { var: '{overdue_task_count}', desc: 'Number of overdue tasks' },
    { var: '{last_activity}', desc: 'Last activity date' },
    { var: '{hourly_rate}', desc: 'Client hourly rate' },
  ],
  'action.client.get_financials': [
    { var: '{client_id}', desc: 'Client ID' },
    { var: '{total_revenue}', desc: 'Total revenue from client' },
    { var: '{total_invoiced}', desc: 'Total invoiced amount' },
    { var: '{total_paid}', desc: 'Total paid amount' },
    { var: '{outstanding}', desc: 'Outstanding balance' },
    { var: '{invoice_count}', desc: 'Number of invoices' },
    { var: '{hourly_rate}', desc: 'Client hourly rate' },
  ],
  'action.client.get_health': [
    { var: '{client_id}', desc: 'Client ID' },
    { var: '{client_name}', desc: 'Client name' },
    { var: '{health_score}', desc: 'Health score (0-100)' },
    { var: '{last_activity}', desc: 'Last activity date' },
    { var: '{days_since_activity}', desc: 'Days since last activity' },
  ],
  'action.invoice.create': [
    { var: '{invoice_id}', desc: 'New invoice ID' },
    { var: '{invoice_number}', desc: 'Generated invoice number' },
    { var: '{total}', desc: 'Invoice total' },
    { var: '{status}', desc: 'Invoice status (draft)' },
  ],
  'action.invoice.send': [
    { var: '{invoice_id}', desc: 'Invoice ID' },
    { var: '{status}', desc: 'Updated status (sent)' },
  ],
  'action.invoice.record_payment': [
    { var: '{invoice_id}', desc: 'Invoice ID' },
    { var: '{payment_amount}', desc: 'Recorded payment amount' },
    { var: '{new_status}', desc: 'Updated invoice status' },
    { var: '{remaining}', desc: 'Remaining balance' },
  ],
  'action.stats.email': [
    { var: '{total_sent}', desc: 'Emails sent in period' },
    { var: '{total_received}', desc: 'Emails received in period' },
    { var: '{avg_reply_time}', desc: 'Average reply time (seconds)' },
    { var: '{period}', desc: 'Statistics period' },
  ],
  'action.stats.response_time': [
    { var: '{avg_reply_time}', desc: 'Average reply time (seconds)' },
    { var: '{avg_reply_time_formatted}', desc: 'Human-readable reply time' },
    { var: '{top_contacts}', desc: 'Array of contacts with reply times' },
  ],
  'action.stats.revenue_report': [
    { var: '{total_revenue}', desc: 'Total revenue in period' },
    { var: '{total_expenses}', desc: 'Total expenses in period' },
    { var: '{net_profit}', desc: 'Net profit (revenue - expenses)' },
    { var: '{period}', desc: 'Report period' },
    { var: '{monthly_breakdown}', desc: 'Array of monthly revenue/expense data' },
  ],
  'action.stats.client_ranking': [
    { var: '{rankings}', desc: 'Array of clients sorted by value (raw data)' },
    { var: '{rankings_list}', desc: 'Formatted ranking list (numbered)' },
    { var: '{top_client}', desc: 'Highest-value client name' },
    { var: '{top_client_revenue}', desc: 'Top client revenue' },
    { var: '{period}', desc: 'Ranking period' },
  ],
  'action.stats.aging_report': [
    { var: '{overdue_count}', desc: 'Number of overdue invoices' },
    { var: '{overdue_total}', desc: 'Total overdue amount' },
    { var: '{invoices_list}', desc: 'Formatted overdue invoices list' },
    { var: '{aging_buckets}', desc: 'Breakdown by age (30/60/90+ days)' },
  ],
  'action.ai.prompt': [
    { var: '{ai_response}', desc: 'Full AI text response' },
    { var: '{ai_model}', desc: 'Model used (e.g. gpt-5-nano)' },
    { var: '{ai_tokens}', desc: 'Total tokens used' },
  ],
  'action.ai.summarize': [
    { var: '{ai_summary}', desc: 'AI-generated summary text' },
    { var: '{ai_key_points}', desc: 'Key points (array)' },
    { var: '{ai_sentiment}', desc: 'Detected sentiment' },
  ],
  'action.ai.rewrite': [
    { var: '{ai_rewritten}', desc: 'Rewritten text' },
    { var: '{ai_style}', desc: 'Writing style used' },
  ],

  // Calendar
  'trigger.calendar.event_created': [
    { var: '{event_id}', desc: 'Event ID' },
    { var: '{event_title}', desc: 'Event title' },
    { var: '{event_start}', desc: 'Start date/time' },
    { var: '{event_end}', desc: 'End date/time' },
    { var: '{calendar_name}', desc: 'Calendar name' },
  ],
  'trigger.calendar.event_upcoming': [
    { var: '{event_id}', desc: 'Event ID' },
    { var: '{event_title}', desc: 'Event title' },
    { var: '{event_start}', desc: 'Start date/time' },
    { var: '{event_end}', desc: 'End date/time' },
    { var: '{minutes_until}', desc: 'Minutes until event starts' },
    { var: '{calendar_name}', desc: 'Calendar name' },
  ],
  'action.calendar.create_event': [
    { var: '{event_id}', desc: 'New event ID' },
    { var: '{event_title}', desc: 'Event title' },
  ],
  'action.calendar.get_events': [
    { var: '{events}', desc: 'Array of events (raw data)' },
    { var: '{events_count}', desc: 'Number of events found' },
    { var: '{events_list}', desc: 'Formatted event list (numbered, with times)' },
  ],
  'action.calendar.update_event': [
    { var: '{event_id}', desc: 'Updated event ID' },
  ],
  'action.calendar.delete_event': [
    { var: '{event_id}', desc: 'Deleted event ID' },
  ],
  'action.calendar.get_upcoming': [
    { var: '{events}', desc: 'Array of upcoming events (raw data)' },
    { var: '{events_count}', desc: 'Number of events' },
    { var: '{events_list}', desc: 'Formatted event list (numbered, with times)' },
    { var: '{next_event_title}', desc: 'Title of next event' },
    { var: '{next_event_start}', desc: 'Start time of next event' },
  ],

  // Drive
  'trigger.drive.file_uploaded': [
    { var: '{file_id}', desc: 'File ID' },
    { var: '{file_name}', desc: 'File name' },
    { var: '{file_type}', desc: 'MIME type' },
    { var: '{file_size}', desc: 'File size in bytes' },
    { var: '{folder_name}', desc: 'Parent folder name' },
  ],
  'trigger.drive.file_updated': [
    { var: '{file_id}', desc: 'File ID' },
    { var: '{file_name}', desc: 'File name' },
    { var: '{file_type}', desc: 'MIME type' },
    { var: '{updated_at}', desc: 'Update timestamp' },
    { var: '{folder_name}', desc: 'Parent folder name' },
  ],
  'action.drive.list_files': [
    { var: '{files}', desc: 'Array of files (raw data)' },
    { var: '{files_count}', desc: 'Number of files' },
    { var: '{files_list}', desc: 'Formatted file list (numbered, with sizes)' },
    { var: '{files_names}', desc: 'Comma-separated file names' },
  ],
  'action.drive.get_file_info': [
    { var: '{file_id}', desc: 'File ID' },
    { var: '{file_name}', desc: 'File name' },
    { var: '{file_type}', desc: 'MIME type' },
    { var: '{file_size}', desc: 'File size in bytes' },
    { var: '{updated_at}', desc: 'Last updated' },
  ],
  'action.drive.create_folder': [
    { var: '{folder_id}', desc: 'New folder ID' },
    { var: '{folder_name}', desc: 'Folder name' },
  ],

  // Weather
  'action.weather.get_current': [
    { var: '{weather_city}', desc: 'City name' },
    { var: '{weather_temp}', desc: 'Temperature' },
    { var: '{weather_description}', desc: 'Weather description' },
    { var: '{weather_humidity}', desc: 'Humidity %' },
    { var: '{weather_wind_speed}', desc: 'Wind speed' },
    { var: '{weather_icon}', desc: 'Weather icon code' },
    { var: '{weather_feels_like}', desc: 'Feels-like temperature' },
  ],

  // Google
  'action.google.get_contacts': [
    { var: '{contacts}', desc: 'Array of contacts (name, email, phone, company)' },
    { var: '{contacts_count}', desc: 'Number of contacts' },
    { var: '{contacts_list}', desc: 'Formatted contact list (numbered)' },
    { var: '{contact_emails}', desc: 'Comma-separated email addresses' },
  ],
  'action.google.get_contact': [
    { var: '{contact_name}', desc: 'Contact display name' },
    { var: '{contact_email}', desc: 'Contact email' },
    { var: '{contact_phone}', desc: 'Contact phone' },
    { var: '{contact_company}', desc: 'Contact company/organization' },
    { var: '{found}', desc: 'Whether contact was found' },
  ],
  'action.google.sync_calendar': [
    { var: '{sync_status}', desc: 'Sync status' },
    { var: '{events_synced}', desc: 'Total events synced' },
    { var: '{events_imported}', desc: 'Events imported' },
    { var: '{events_updated}', desc: 'Events updated' },
  ],

  // Email Campaigns
  'action.campaign.get_stats': [
    { var: '{campaign_id}', desc: 'Campaign UUID' },
    { var: '{campaign_subject}', desc: 'Campaign subject line' },
    { var: '{campaign_status}', desc: 'Status (draft/pending/processing/completed)' },
    { var: '{total_recipients}', desc: 'Total recipients' },
    { var: '{sent_count}', desc: 'Emails sent' },
    { var: '{failed_count}', desc: 'Failed sends' },
    { var: '{open_count}', desc: 'Unique opens' },
    { var: '{open_rate}', desc: 'Open rate (%)' },
    { var: '{click_count}', desc: 'Unique clicks' },
    { var: '{click_rate}', desc: 'Click rate (%)' },
    { var: '{bounce_count}', desc: 'Bounced emails' },
    { var: '{unsubscribe_count}', desc: 'Unsubscribes' },
    { var: '{progress_percent}', desc: 'Send progress (%)' },
    { var: '{mailing_list_name}', desc: 'Mailing list name' },
  ],
  'action.campaign.send': [
    { var: '{campaign_id}', desc: 'Sent campaign UUID' },
    { var: '{total_recipients}', desc: 'Number of recipients queued' },
    { var: '{skipped_unsubscribed}', desc: 'Skipped (unsubscribed)' },
  ],

  // Trello
  'action.trello.sync_boards': [
    { var: '{boards_synced}', desc: 'Number of boards synced' },
    { var: '{cards_synced}', desc: 'Number of cards synced' },
  ],
  'action.trello.get_boards': [
    { var: '{boards}', desc: 'Array of Trello boards' },
    { var: '{boards_count}', desc: 'Number of boards' },
    { var: '{lists}', desc: 'Lists with cards (if board selected)' },
    { var: '{lists_count}', desc: 'Number of lists' },
  ],
  // SQL Query
  'action.sql.query': [
    { var: '{rows}', desc: 'Array of result rows' },
    { var: '{row_count}', desc: 'Number of rows returned' },
    { var: '{table}', desc: 'Table queried' },
    { var: '{query}', desc: 'SQL query executed' },
  ],

  // Lists
  'action.list.get_mailing_list': [
    { var: '{contacts}', desc: 'Array of contacts' },
    { var: '{contacts_count}', desc: 'Number of contacts' },
    { var: '{contacts_list}', desc: 'Formatted contact list' },
    { var: '{list_name}', desc: 'Mailing list name' },
    { var: '{contact_emails}', desc: 'Comma-separated emails' },
  ],
  'action.list.get_team': [
    { var: '{members}', desc: 'Array of team members' },
    { var: '{members_count}', desc: 'Number of members' },
    { var: '{members_list}', desc: 'Formatted member list' },
    { var: '{group_name}', desc: 'Team group name' },
    { var: '{member_emails}', desc: 'Comma-separated emails' },
  ],
  'action.list.add_contact': [
    { var: '{contact_id}', desc: 'New contact ID' },
    { var: '{contact_email}', desc: 'Added email' },
    { var: '{list_name}', desc: 'Mailing list name' },
  ],
  'action.list.remove_contact': [
    { var: '{removed_email}', desc: 'Removed email address' },
    { var: '{list_name}', desc: 'Mailing list name' },
  ],

  // Sequences
  'action.sequence.start': [
    { var: '{enrollment_id}', desc: 'Sequence enrollment ID' },
    { var: '{sequence_name}', desc: 'Sequence name' },
    { var: '{client_id}', desc: 'Enrolled client ID' },
  ],
  'action.sequence.stop': [
    { var: '{enrollment_id}', desc: 'Enrollment ID' },
    { var: '{status}', desc: 'Status after cancellation' },
  ],
  'action.sequence.get_status': [
    { var: '{enrollment_id}', desc: 'Enrollment ID' },
    { var: '{sequence_name}', desc: 'Sequence name' },
    { var: '{status}', desc: 'Current status' },
    { var: '{current_step}', desc: 'Current step number' },
    { var: '{total_steps}', desc: 'Total steps in sequence' },
    { var: '{next_run_at}', desc: 'Next scheduled run' },
    { var: '{started_at}', desc: 'Enrollment start date' },
  ],

  // Moodboards
  'action.moodboard.get_info': [
    { var: '{moodboard_id}', desc: 'Moodboard ID' },
    { var: '{moodboard_name}', desc: 'Moodboard name' },
    { var: '{client_id}', desc: 'Associated client ID' },
    { var: '{item_count}', desc: 'Number of items' },
    { var: '{archived}', desc: 'Whether archived' },
    { var: '{created_at}', desc: 'Created date' },
    { var: '{updated_at}', desc: 'Last updated date' },
  ],
  'action.moodboard.list': [
    { var: '{moodboards}', desc: 'Array of moodboards' },
    { var: '{moodboard_count}', desc: 'Number of moodboards' },
    { var: '{moodboards_list}', desc: 'Formatted moodboard list' },
  ],
  'action.moodboard.share': [
    { var: '{share_url}', desc: 'Public share URL' },
    { var: '{share_token}', desc: 'Share token' },
    { var: '{moodboard_name}', desc: 'Moodboard name' },
    { var: '{sent_to}', desc: 'Email it was sent to' },
  ],

  // Invoice / Billingo
  'action.invoice.push_billingo': [
    { var: '{invoice_id}', desc: 'Invoice ID' },
    { var: '{external_invoice_id}', desc: 'Billingo invoice ID' },
    { var: '{external_invoice_url}', desc: 'Billingo invoice URL' },
    { var: '{external_pdf_url}', desc: 'PDF download URL' },
    { var: '{provider}', desc: 'Billing provider name' },
  ],
  'action.invoice.download_pdf': [
    { var: '{invoice_id}', desc: 'Invoice ID' },
    { var: '{file_id}', desc: 'Drive file ID' },
    { var: '{file_name}', desc: 'PDF file name' },
    { var: '{folder_name}', desc: 'Target folder name' },
    { var: '{download_url}', desc: 'PDF download URL' },
  ],
  'action.invoice.send_to_client': [
    { var: '{invoice_id}', desc: 'Invoice ID' },
    { var: '{invoice_number}', desc: 'Invoice number' },
    { var: '{sent_to}', desc: 'Recipient email' },
  ],
  'action.invoice.get_status': [
    { var: '{invoice_id}', desc: 'Invoice ID' },
    { var: '{invoice_number}', desc: 'Invoice number' },
    { var: '{status}', desc: 'Payment status' },
    { var: '{total}', desc: 'Invoice total' },
    { var: '{paid_amount}', desc: 'Amount paid' },
    { var: '{currency}', desc: 'Currency code' },
    { var: '{due_date}', desc: 'Due date' },
    { var: '{client_name}', desc: 'Client name' },
    { var: '{external_status}', desc: 'Provider sync status' },
    { var: '{payment_date}', desc: 'Last payment date' },
  ],

  'action.printer.list': [
    { var: '{printers}', desc: 'Array of printers (name, displayName, status, isDefault)' },
    { var: '{printers_count}', desc: 'Number of printers detected' },
    { var: '{default_printer}', desc: 'Name of the default printer' },
  ],
  'action.printer.print': [
    { var: '{print_success}', desc: 'Whether print was successful (true/false)' },
    { var: '{print_printer}', desc: 'Printer name used' },
    { var: '{print_error}', desc: 'Error message if print failed' },
  ],

  // Mailchimp
  'action.mailchimp.get_lists': [
    { var: '{lists}', desc: 'Array of Mailchimp audiences (id, name, member_count)' },
    { var: '{lists_count}', desc: 'Number of audiences' },
    { var: '{lists_list}', desc: 'Formatted list (numbered)' },
  ],
  'action.mailchimp.get_members': [
    { var: '{members}', desc: 'Array of subscribers (email, status, merge_fields)' },
    { var: '{members_count}', desc: 'Number of members returned' },
    { var: '{members_list}', desc: 'Formatted member list (numbered)' },
    { var: '{member_emails}', desc: 'Comma-separated email addresses' },
    { var: '{list_name}', desc: 'Audience name' },
    { var: '{total_items}', desc: 'Total members in audience' },
  ],
  'action.mailchimp.add_member': [
    { var: '{member_email}', desc: 'Subscribed email address' },
    { var: '{member_id}', desc: 'Mailchimp member unique ID' },
    { var: '{member_status}', desc: 'Subscription status' },
    { var: '{list_name}', desc: 'Audience name' },
  ],
  'action.mailchimp.remove_member': [
    { var: '{removed_email}', desc: 'Unsubscribed email address' },
    { var: '{member_status}', desc: 'Status after removal (unsubscribed)' },
    { var: '{list_name}', desc: 'Audience name' },
  ],
  'action.mailchimp.get_campaigns': [
    { var: '{campaigns}', desc: 'Array of campaigns (id, title, subject, status, send_time)' },
    { var: '{campaigns_count}', desc: 'Number of campaigns returned' },
    { var: '{campaigns_list}', desc: 'Formatted campaign list (numbered)' },
    { var: '{total_items}', desc: 'Total campaigns in account' },
  ],
  'action.mailchimp.send_campaign': [
    { var: '{campaign_id}', desc: 'Sent campaign ID' },
    { var: '{campaign_title}', desc: 'Campaign title' },
    { var: '{send_status}', desc: 'Send status (sent/schedule)' },
  ],

  'logic.condition': [
    { var: 'All upstream vars', desc: 'Reference any field with dot notation, e.g. data.amount' },
  ],
  'logic.delay': [
    { var: 'All upstream vars', desc: 'All data is preserved through the delay' },
  ],
  'logic.filter': [
    { var: 'All upstream vars', desc: 'Reference any field with dot notation' },
  ],
  'logic.merge': [
    { var: '{input_a.*}', desc: 'Data from input A' },
    { var: '{input_b.*}', desc: 'Data from input B' },
  ],
}

const nodeRegistry = {
  // ── TRIGGERS ──────────────────────────────────────────────────────────
  'trigger.board.card_moved': {
    category: 'trigger', label: 'Card Moved', subtitle: 'When a card moves to a list',
    icon: 'swap_horiz', inputs: [], outputs: [{ id: 'output', label: '' }], group: 'Board',
  },
  'trigger.board.card_created': {
    category: 'trigger', label: 'Card Created', subtitle: 'When a new card is created',
    icon: 'add_card', inputs: [], outputs: [{ id: 'output', label: '' }], group: 'Board',
  },
  'trigger.board.card_completed': {
    category: 'trigger', label: 'Card Completed', subtitle: 'When a card is marked complete',
    icon: 'task_alt', inputs: [], outputs: [{ id: 'output', label: '' }], group: 'Board',
  },
  'trigger.board.card_overdue': {
    category: 'trigger', label: 'Card Overdue', subtitle: 'When a card passes its due date',
    icon: 'schedule', inputs: [], outputs: [{ id: 'output', label: '' }], group: 'Board',
  },
  'trigger.crm.deal_stage_changed': {
    category: 'trigger', label: 'Deal Stage Changed', subtitle: 'When a deal moves to a new stage',
    icon: 'trending_up', inputs: [], outputs: [{ id: 'output', label: '' }], group: 'CRM',
  },
  'trigger.crm.deal_won': {
    category: 'trigger', label: 'Deal Won', subtitle: 'When a deal is marked as won',
    icon: 'emoji_events', inputs: [], outputs: [{ id: 'output', label: '' }], group: 'CRM',
  },
  'trigger.crm.deal_lost': {
    category: 'trigger', label: 'Deal Lost', subtitle: 'When a deal is marked as lost',
    icon: 'thumb_down', inputs: [], outputs: [{ id: 'output', label: '' }], group: 'CRM',
  },
  'trigger.crm.invoice_overdue': {
    category: 'trigger', label: 'Invoice Overdue', subtitle: 'When an invoice is past due',
    icon: 'receipt_long', inputs: [], outputs: [{ id: 'output', label: '' }], group: 'CRM',
  },
  'trigger.client.health_low': {
    category: 'trigger', label: 'Client Health Low', subtitle: 'When health score drops below threshold',
    icon: 'heart_broken', inputs: [], outputs: [{ id: 'output', label: '' }], group: 'Clients',
  },
  'trigger.client.inactive': {
    category: 'trigger', label: 'Client Inactive', subtitle: 'When a client has no activity',
    icon: 'person_off', inputs: [], outputs: [{ id: 'output', label: '' }], group: 'Clients',
  },
  'trigger.invoice.paid': {
    category: 'trigger', label: 'Invoice Paid', subtitle: 'When an invoice is marked paid',
    icon: 'paid', inputs: [], outputs: [{ id: 'output', label: '' }], group: 'Financial',
  },
  'trigger.invoice.created': {
    category: 'trigger', label: 'Invoice Created', subtitle: 'When a new invoice is created',
    icon: 'note_add', inputs: [], outputs: [{ id: 'output', label: '' }], group: 'Financial',
  },
  'trigger.financial.threshold': {
    category: 'trigger', label: 'Financial Threshold', subtitle: 'When revenue/expenses cross a limit',
    icon: 'price_check', inputs: [], outputs: [{ id: 'output', label: '' }], group: 'Financial',
  },
  'trigger.server.health': {
    category: 'trigger', label: 'Server Health', subtitle: 'Monitor CPU, RAM, disk, services',
    icon: 'monitor_heart', inputs: [], outputs: [{ id: 'output', label: '' }], group: 'Server',
  },
  'trigger.manual': {
    category: 'trigger', label: 'Manual Trigger', subtitle: 'Run workflow manually via Test button',
    icon: 'play_circle', inputs: [], outputs: [{ id: 'output', label: '' }], group: 'Core',
  },
  'trigger.schedule.cron': {
    category: 'trigger', label: 'Schedule', subtitle: 'Run on a time schedule',
    icon: 'timer', inputs: [], outputs: [{ id: 'output', label: '' }], group: 'Core',
  },
  'trigger.webhook.incoming': {
    category: 'trigger', label: 'Webhook', subtitle: 'Triggered by HTTP request',
    icon: 'webhook', inputs: [], outputs: [{ id: 'output', label: '' }], group: 'Core',
  },
  'trigger.telegram.message': {
    category: 'trigger', label: 'Telegram Message', subtitle: 'When a message is received',
    icon: 'send', inputs: [], outputs: [{ id: 'output', label: '' }], group: 'Telegram',
  },

  // ── CALENDAR TRIGGERS ────────────────────────────────────────────────
  'trigger.calendar.event_created': {
    category: 'trigger', label: 'Event Created', subtitle: 'When a calendar event is created',
    icon: 'event_available', inputs: [], outputs: [{ id: 'output', label: '' }], group: 'Calendar',
  },
  'trigger.calendar.event_upcoming': {
    category: 'trigger', label: 'Event Upcoming', subtitle: 'Before an event starts',
    icon: 'upcoming', inputs: [], outputs: [{ id: 'output', label: '' }], group: 'Calendar',
  },

  // ── DRIVE TRIGGERS ──────────────────────────────────────────────────
  'trigger.drive.file_uploaded': {
    category: 'trigger', label: 'File Uploaded', subtitle: 'When a new file is uploaded',
    icon: 'upload_file', inputs: [], outputs: [{ id: 'output', label: '' }], group: 'Drive',
  },
  'trigger.drive.file_updated': {
    category: 'trigger', label: 'File Updated', subtitle: 'When a file is modified',
    icon: 'sync', inputs: [], outputs: [{ id: 'output', label: '' }], group: 'Drive',
  },

  // ── ACTIONS ───────────────────────────────────────────────────────────
  'action.email.send': {
    category: 'action', label: 'Send Email', subtitle: 'Send an email message',
    icon: 'mail', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Communication',
  },
  'action.chat.send': {
    category: 'action', label: 'Send Chat Message', subtitle: 'Post to a chat or channel',
    icon: 'chat', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Communication',
  },
  'action.notification.send': {
    category: 'action', label: 'Send Notification', subtitle: 'Push notification to user',
    icon: 'notifications', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Communication',
  },
  'action.telegram.send': {
    category: 'action', label: 'Telegram Send', subtitle: 'Send a Telegram message',
    icon: 'send', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Telegram',
  },
  'action.http.request': {
    category: 'action', label: 'HTTP Request', subtitle: 'Make an API call',
    icon: 'http', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Core',
  },
  'action.crm.move_deal': {
    category: 'action', label: 'Move Deal', subtitle: 'Move deal to a pipeline stage',
    icon: 'move_up', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'CRM',
  },
  'action.board.move_card': {
    category: 'action', label: 'Move Card', subtitle: 'Move card to another list',
    icon: 'drag_indicator', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Board',
  },
  'action.task.create': {
    category: 'action', label: 'Create Task', subtitle: 'Create a new task / card',
    icon: 'add_task', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Board',
  },
  'action.export.csv': {
    category: 'action', label: 'Export to CSV', subtitle: 'Export data to CSV and save to Drive',
    icon: 'download', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Data',
  },
  'action.client.get_data': {
    category: 'action', label: 'Get Client Data', subtitle: 'Fetch client details and contacts',
    icon: 'person_search', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Clients',
  },
  'action.client.get_financials': {
    category: 'action', label: 'Client Financials', subtitle: 'Get client revenue and invoices',
    icon: 'account_balance', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Clients',
  },
  'action.client.get_health': {
    category: 'action', label: 'Client Health Score', subtitle: 'Calculate client health score',
    icon: 'health_and_safety', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Clients',
  },
  'action.invoice.create': {
    category: 'action', label: 'Create Invoice', subtitle: 'Create a new invoice for a client',
    icon: 'receipt', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Financial',
  },
  'action.invoice.send': {
    category: 'action', label: 'Send Invoice', subtitle: 'Mark invoice as sent',
    icon: 'forward_to_inbox', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Financial',
  },
  'action.invoice.record_payment': {
    category: 'action', label: 'Record Payment', subtitle: 'Record a payment on an invoice',
    icon: 'payments', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Financial',
  },
  'action.stats.email': {
    category: 'action', label: 'Email Statistics', subtitle: 'Get email stats for a period',
    icon: 'query_stats', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Statistics',
  },
  'action.stats.response_time': {
    category: 'action', label: 'Response Time', subtitle: 'Get avg reply/response times',
    icon: 'speed', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Statistics',
  },
  'action.stats.revenue_report': {
    category: 'action', label: 'Revenue Report', subtitle: 'Get revenue and profitability data',
    icon: 'bar_chart', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Statistics',
  },
  'action.stats.client_ranking': {
    category: 'action', label: 'Client Ranking', subtitle: 'Rank clients by value',
    icon: 'leaderboard', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Statistics',
  },
  'action.stats.aging_report': {
    category: 'action', label: 'Invoice Aging', subtitle: 'Overdue invoice breakdown',
    icon: 'update', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Statistics',
  },

  // ── CALENDAR ACTIONS ─────────────────────────────────────────────────
  'action.calendar.create_event': {
    category: 'action', label: 'Create Event', subtitle: 'Create a calendar event',
    icon: 'edit_calendar', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Calendar',
  },
  'action.calendar.get_events': {
    category: 'action', label: 'Get Events', subtitle: 'Get events for a date range',
    icon: 'date_range', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Calendar',
  },
  'action.calendar.update_event': {
    category: 'action', label: 'Update Event', subtitle: 'Update an existing event',
    icon: 'event_note', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Calendar',
  },
  'action.calendar.delete_event': {
    category: 'action', label: 'Delete Event', subtitle: 'Delete a calendar event',
    icon: 'event_busy', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Calendar',
  },
  'action.calendar.get_upcoming': {
    category: 'action', label: 'Upcoming Events', subtitle: 'Get next N upcoming events',
    icon: 'calendar_month', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Calendar',
  },

  // ── DRIVE ACTIONS ───────────────────────────────────────────────────
  'action.drive.list_files': {
    category: 'action', label: 'List Files', subtitle: 'List files in a folder',
    icon: 'folder_open', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Drive',
  },
  'action.drive.get_file_info': {
    category: 'action', label: 'Get File Info', subtitle: 'Get file metadata',
    icon: 'description', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Drive',
  },
  'action.drive.create_folder': {
    category: 'action', label: 'Create Folder', subtitle: 'Create a new drive folder',
    icon: 'create_new_folder', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Drive',
  },

  // ── WEATHER ─────────────────────────────────────────────────────────
  'action.weather.get_current': {
    category: 'action', label: 'Get Weather', subtitle: 'Current weather for a location',
    icon: 'cloud', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Weather',
  },

  // ── GOOGLE ──────────────────────────────────────────────────────────
  'action.google.get_contacts': {
    category: 'action', label: 'Get Contacts', subtitle: 'Fetch Google contacts',
    icon: 'contacts', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Google',
  },
  'action.google.get_contact': {
    category: 'action', label: 'Find Contact', subtitle: 'Find a Google contact by name/email',
    icon: 'person_search', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Google',
  },
  'action.google.sync_calendar': {
    category: 'action', label: 'Sync Google Calendar', subtitle: 'Force Google Calendar sync',
    icon: 'sync', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Google',
  },

  // ── EMAIL CAMPAIGNS ────────────────────────────────────────────────
  'action.campaign.get_stats': {
    category: 'action', label: 'Campaign Stats', subtitle: 'Get email campaign statistics',
    icon: 'campaign', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Email Marketing',
  },
  'action.campaign.send': {
    category: 'action', label: 'Send Campaign', subtitle: 'Send an email campaign',
    icon: 'send', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Email Marketing',
  },

  // ── TRELLO ──────────────────────────────────────────────────────────
  'action.trello.sync_boards': {
    category: 'action', label: 'Sync Trello Boards', subtitle: 'Import Trello boards to local',
    icon: 'developer_board', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Trello',
  },
  'action.trello.get_boards': {
    category: 'action', label: 'Get Trello Boards', subtitle: 'Fetch Trello boards and cards',
    icon: 'view_kanban', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Trello',
  },

  // ── AI ───────────────────────────────────────────────────────────────
  'action.ai.prompt': {
    category: 'action', label: 'AI Prompt', subtitle: 'Send a prompt to AI and get a response',
    icon: 'auto_awesome', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'AI',
  },
  'action.ai.summarize': {
    category: 'action', label: 'AI Summarize', subtitle: 'Summarize text content with AI',
    icon: 'summarize', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'AI',
  },
  'action.ai.rewrite': {
    category: 'action', label: 'AI Rewrite', subtitle: 'Rewrite text in a chosen style',
    icon: 'edit_note', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'AI',
  },

  // ── SQL ──────────────────────────────────────────────────────────────
  'action.sql.query': {
    category: 'action', label: 'SQL Query', subtitle: 'Run a read-only database query',
    icon: 'database', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Database',
  },

  // ── LISTS ───────────────────────────────────────────────────────────
  'action.list.get_mailing_list': {
    category: 'action', label: 'Get Mailing List', subtitle: 'Fetch contacts from a mailing list',
    icon: 'contact_mail', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Lists',
  },
  'action.list.get_team': {
    category: 'action', label: 'Get Team Group', subtitle: 'Fetch team or group members',
    icon: 'group', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Lists',
  },
  'action.list.add_contact': {
    category: 'action', label: 'Add to Mailing List', subtitle: 'Add a contact to a mailing list',
    icon: 'person_add', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Lists',
  },
  'action.list.remove_contact': {
    category: 'action', label: 'Remove from List', subtitle: 'Remove a contact from a mailing list',
    icon: 'person_remove', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Lists',
  },

  // ── SEQUENCES ───────────────────────────────────────────────────────
  'action.sequence.start': {
    category: 'action', label: 'Start Sequence', subtitle: 'Enroll a contact in an email sequence',
    icon: 'play_circle', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Sequences',
  },
  'action.sequence.stop': {
    category: 'action', label: 'Stop Sequence', subtitle: 'Cancel an active sequence enrollment',
    icon: 'stop_circle', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Sequences',
  },
  'action.sequence.get_status': {
    category: 'action', label: 'Sequence Status', subtitle: 'Check sequence enrollment status',
    icon: 'info', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Sequences',
  },

  // ── MOODBOARDS ──────────────────────────────────────────────────────
  'action.moodboard.get_info': {
    category: 'action', label: 'Moodboard Info', subtitle: 'Get details about a moodboard',
    icon: 'dashboard', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Moodboards',
  },
  'action.moodboard.list': {
    category: 'action', label: 'List Moodboards', subtitle: 'List moodboards with optional filter',
    icon: 'grid_view', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Moodboards',
  },
  'action.moodboard.share': {
    category: 'action', label: 'Share Moodboard', subtitle: 'Generate share link and send via email',
    icon: 'share', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Moodboards',
  },

  // ── INVOICE / BILLINGO ──────────────────────────────────────────────
  'action.invoice.push_billingo': {
    category: 'action', label: 'Push to Billingo', subtitle: 'Send invoice to billing provider',
    icon: 'cloud_upload', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Invoice',
  },
  'action.invoice.download_pdf': {
    category: 'action', label: 'Download Invoice PDF', subtitle: 'Download PDF and save to Drive',
    icon: 'picture_as_pdf', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Invoice',
  },
  'action.invoice.send_to_client': {
    category: 'action', label: 'Send Invoice', subtitle: 'Email invoice to client',
    icon: 'forward_to_inbox', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Invoice',
  },
  'action.invoice.get_status': {
    category: 'action', label: 'Invoice Status', subtitle: 'Check invoice payment status',
    icon: 'receipt_long', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Invoice',
  },

  // ── PRINTER ───────────────────────────────────────────────────────────
  'action.printer.list': {
    category: 'action', label: 'List Printers', subtitle: 'Get available printers from local machine',
    icon: 'print', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Printer',
  },
  'action.printer.print': {
    category: 'action', label: 'Print Document', subtitle: 'Send a document to a printer',
    icon: 'print', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Printer',
  },

  // ── MAILCHIMP ──────────────────────────────────────────────────────────
  'action.mailchimp.get_lists': {
    category: 'action', label: 'MC Get Lists', subtitle: 'Fetch Mailchimp audiences',
    icon: 'lists', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Mailchimp',
  },
  'action.mailchimp.get_members': {
    category: 'action', label: 'MC Get Members', subtitle: 'Fetch subscribers from an audience',
    icon: 'group', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Mailchimp',
  },
  'action.mailchimp.add_member': {
    category: 'action', label: 'MC Add Member', subtitle: 'Add or update a subscriber',
    icon: 'person_add', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Mailchimp',
  },
  'action.mailchimp.remove_member': {
    category: 'action', label: 'MC Remove Member', subtitle: 'Unsubscribe a contact',
    icon: 'person_remove', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Mailchimp',
  },
  'action.mailchimp.get_campaigns': {
    category: 'action', label: 'MC Get Campaigns', subtitle: 'Fetch Mailchimp campaigns',
    icon: 'campaign', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Mailchimp',
  },
  'action.mailchimp.send_campaign': {
    category: 'action', label: 'MC Send Campaign', subtitle: 'Send a Mailchimp campaign',
    icon: 'send', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Mailchimp',
  },

  // ── LOGIC ─────────────────────────────────────────────────────────────
  'logic.condition': {
    category: 'logic', label: 'Condition', subtitle: 'If / else branch',
    icon: 'call_split', inputs: [{ id: 'input', label: '' }],
    outputs: [{ id: 'true', label: 'True' }, { id: 'false', label: 'False' }], group: 'Logic',
  },
  'logic.delay': {
    category: 'logic', label: 'Delay', subtitle: 'Wait before continuing',
    icon: 'hourglass_empty', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Logic',
  },
  'logic.filter': {
    category: 'logic', label: 'Filter', subtitle: 'Pass or block data',
    icon: 'filter_alt', inputs: [{ id: 'input', label: '' }], outputs: [{ id: 'output', label: '' }], group: 'Logic',
  },
  'logic.merge': {
    category: 'logic', label: 'Merge', subtitle: 'Combine multiple inputs',
    icon: 'merge', inputs: [{ id: 'input_a', label: 'A' }, { id: 'input_b', label: 'B' }],
    outputs: [{ id: 'output', label: '' }], group: 'Logic',
  },
}

// Nodes that only appear when backend conditionally includes them
const CONDITIONAL_NODES = new Set([
  'action.ai.prompt',
  'action.ai.summarize',
  'action.ai.rewrite',
  'action.weather.get_current',
  'action.google.get_contacts',
  'action.google.get_contact',
  'action.google.sync_calendar',
  'action.trello.sync_boards',
  'action.trello.get_boards',
  'action.printer.list',
  'action.printer.print',
  'action.mailchimp.get_lists',
  'action.mailchimp.get_members',
  'action.mailchimp.add_member',
  'action.mailchimp.remove_member',
  'action.mailchimp.get_campaigns',
  'action.mailchimp.send_campaign',
])

const _activeRegistry = ref(
  Object.fromEntries(
    Object.entries(nodeRegistry).filter(([k]) => !CONDITIONAL_NODES.has(k))
  )
)
let _synced = false

function _syncRegistry() {
  automationHubApi.getNodeRegistry()
    .then(res => {
      const backendReg = res?.data?.data?.registry || res?.data?.registry || {}
      const keys = new Set(Object.keys(backendReg))
      const merged = { ...nodeRegistry }
      for (const k of CONDITIONAL_NODES) {
        if (!keys.has(k)) delete merged[k]
      }
      _activeRegistry.value = merged
    })
    .catch(() => {})
}

function _syncOnce() {
  if (_synced) return
  _synced = true
  _syncRegistry()
}

function refreshRegistry() {
  _syncRegistry()
}

export function useNodeRegistry() {
  _syncOnce()
  const registry = _activeRegistry

  const nodeTypes = computed(() => Object.keys(registry.value))

  const groupedNodes = computed(() => {
    const groups = {}
    for (const [type, def] of Object.entries(registry.value)) {
      const group = def.group || 'Other'
      if (!groups[group]) groups[group] = []
      groups[group].push({ type, ...def })
    }
    return groups
  })

  const categorizedNodes = computed(() => {
    const cats = { trigger: [], action: [], logic: [] }
    for (const [type, def] of Object.entries(registry.value)) {
      cats[def.category]?.push({ type, ...def })
    }
    return cats
  })

  function getNodeDef(type) {
    return registry.value[type] || nodeRegistry[type] || null
  }

  function getCategoryColors(category) {
    return CATEGORY_COLORS[category] || CATEGORY_COLORS.action
  }

  function getVariableDocs(type) {
    return VARIABLE_DOCS[type] || null
  }

  return {
    registry,
    nodeTypes,
    groupedNodes,
    categorizedNodes,
    getNodeDef,
    getCategoryColors,
    getVariableDocs,
    refreshRegistry,
    CATEGORY_COLORS,
    VARIABLE_DOCS,
  }
}
