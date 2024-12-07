# PropertEase API

PropertEase API is a backend API designed for managing real estate projects. It provides robust functionalities for user authentication, project creation, updates, search, and secure JWT-based route access. Built with Symfony, it ensures scalability and maintainability.

## Features

- **User Authentication**: Registration and login with secure JWT tokens.
- **Project Management**:
  - Create new real estate projects.
  - Update existing projects (excluding the project title).
  - Delete projects (mark as inactive).
  - Retrieve a list of all projects.
  - Search projects by title and delivery date range.
  - View detailed information for a specific project by ID.
- **Secure Routes**: Only accessible to authenticated users.

## Installation

### Prerequisites

- PHP 8.1 or higher
- Composer
- Docker and Docker Compose
- Symfony CLI (optional but recommended)

### Steps

1. Clone the repository:

   ```bash
   git clone https://github.com/your-username/propretease-api.git
   cd propretease-api
   ```

2. Install dependencies:

   ```bash
   composer install
   ```

3. Set up environment variables:
   Copy the `.env` file and adjust database configuration if needed.

   ```bash
   cp .env .env.local
   ```

4. Start Docker services:

   ```bash
   docker-compose up -d
   ```

5. Run database migrations:

   ```bash
   symfony console doctrine:migrations:migrate
   ```

6. Load initial data (optional):

   ```bash
   symfony console doctrine:fixtures:load
   ```

7. Start the Symfony development server (if not using Docker):
   ```bash
   symfony server:start
   ```

## Endpoints

### Authentication

- **Register**: `POST /signup`
  ```json
  {
    "firstName": "John",
    "lastName": "Doe",
    "phoneNumber": "123456789",
    "email": "john.doe@example.com",
    "password": "securePassword"
  }
  ```
- **Login**: `POST /signin`
  ```json
  {
    "email": "john.doe@example.com",
    "password": "securePassword"
  }
  ```

### Project Management

- **Create Project**: `POST /project`
- **Update Project**: `PATCH /project/{id}`
- **Delete Project**: `DELETE /project/{id}`
- **List Projects**: `GET /project`
- **View Project by ID**: `GET /project/{id}`
- **Search Projects**: `GET /project/search`
  - Parameters: `title`, `deliveryDateMin`, `deliveryDateMax`

## Security

This API uses JWT for securing endpoints. Ensure you include the token in the `Authorization` header for protected routes:

```
Authorization: Bearer <your-jwt-token>
```

## Tests

Run the test suite with:

```bash
php bin/phpunit
```

## License

This project is open-source and available under the [MIT License](LICENSE).
